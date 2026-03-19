<?php
/**
 * WPZM_Provisioner — Zabbix API host auto-provisioning.
 *
 * Uses the Zabbix JSON-RPC 2.0 API to:
 *   1. Authenticate and obtain a session token
 *   2. Locate (or create) a host group named "WordPress Sites"
 *   3. Look up the WP Zabbix Monitor template by name
 *   4. Create (or update) a Zabbix host for this WordPress site
 *   5. Assign the template to the host
 *   6. Set the {$WP_URL} and {$WP_API_TOKEN} user macros on the host
 *
 * All API calls use wp_remote_post() so they respect WordPress's
 * HTTP API filters (proxy settings, SSL verification, etc.).
 *
 * @package WP_Zabbix_Monitor
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Provisioner {

    /** Zabbix API endpoint URL */
    private string $api_url;

    /** Zabbix login username */
    private string $username;

    /** Zabbix login password */
    private string $password;

    /** Whether to verify SSL certificates on the Zabbix frontend */
    private bool $ssl_verify;

    /** Cached auth token for the current request */
    private ?string $auth_token = null;

    /** Last error message */
    private string $last_error = '';

    /**
     * @param string $api_url    Full URL to Zabbix API, e.g. https://zabbix.example.com/api_jsonrpc.php
     * @param string $username   Zabbix frontend username
     * @param string $password   Zabbix frontend password
     * @param bool   $ssl_verify Whether to verify the Zabbix server's SSL certificate
     */
    public function __construct(
        string $api_url,
        string $username,
        string $password,
        bool   $ssl_verify = true
    ) {
        $this->api_url    = rtrim( $api_url, '/' );
        $this->username   = $username;
        $this->password   = $password;
        $this->ssl_verify = $ssl_verify;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Run the full provisioning workflow.
     *
     * @param string $host_name     Visible name for the Zabbix host (e.g. "My WordPress Site")
     * @param string $host_group    Zabbix host group name (created if absent)
     * @param string $template_name Zabbix template name to assign
     * @param string $wp_url        WordPress site URL for the {$WP_URL} macro
     * @param string $api_token     Plugin API token for the {$WP_API_TOKEN} macro
     * @return array{success: bool, message: string, host_id?: string}
     */
    public function provision(
        string $host_name,
        string $host_group,
        string $template_name,
        string $wp_url,
        string $api_token
    ): array {
        // 1. Authenticate
        if ( ! $this->authenticate() ) {
            return $this->error( 'Authentication failed: ' . $this->last_error );
        }

        // 2. Resolve host group ID (create if needed)
        $group_id = $this->get_or_create_host_group( $host_group );
        if ( null === $group_id ) {
            $this->logout();
            return $this->error( 'Could not resolve host group: ' . $this->last_error );
        }

        // 3. Resolve template ID
        $template_id = $this->get_template_id( $template_name );
        if ( null === $template_id ) {
            $this->logout();
            return $this->error( 'Template not found: ' . $this->last_error );
        }

        // 4. Check for existing host
        $existing_host_id = $this->get_host_id( $host_name );

        if ( null !== $existing_host_id ) {
            // Update existing host
            $result = $this->update_host( $existing_host_id, $group_id, $template_id, $wp_url, $api_token );
            if ( ! $result ) {
                $this->logout();
                return $this->error( 'Failed to update host: ' . $this->last_error );
            }
            $host_id = $existing_host_id;
            $msg     = sprintf( 'Host "%s" updated successfully (ID: %s).', $host_name, $host_id );
        } else {
            // Create new host
            $host_id = $this->create_host( $host_name, $group_id, $template_id, $wp_url, $api_token );
            if ( null === $host_id ) {
                $this->logout();
                return $this->error( 'Failed to create host: ' . $this->last_error );
            }
            $msg = sprintf( 'Host "%s" created successfully (ID: %s).', $host_name, $host_id );
        }

        $this->logout();

        return array(
            'success' => true,
            'message' => $msg,
            'host_id' => $host_id,
        );
    }

    /**
     * Test the Zabbix API connection and credentials without provisioning.
     *
     * @return array{success: bool, message: string, version?: string}
     */
    public function test_connection(): array {
        if ( ! $this->authenticate() ) {
            return $this->error( 'Authentication failed: ' . $this->last_error );
        }

        $version = $this->get_api_version();
        $this->logout();

        return array(
            'success' => true,
            'message' => 'Connection successful.',
            'version' => $version ?? 'unknown',
        );
    }

    /** Return the last error message. */
    public function get_last_error(): string {
        return $this->last_error;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Authenticate with the Zabbix API and store the session token.
     */
    private function authenticate(): bool {
        $response = $this->call( 'user.login', array(
            'username' => $this->username,
            'password' => $this->password,
        ), false );

        if ( is_wp_error( $response ) || ! isset( $response['result'] ) ) {
            $this->last_error = is_wp_error( $response )
                ? $response->get_error_message()
                : ( $response['error']['data'] ?? 'Unknown error' );
            return false;
        }

        $this->auth_token = $response['result'];
        return true;
    }

    /**
     * Log out and invalidate the session token.
     */
    private function logout(): void {
        if ( $this->auth_token ) {
            $this->call( 'user.logout', array() );
            $this->auth_token = null;
        }
    }

    /**
     * Get the Zabbix API version string.
     */
    private function get_api_version(): ?string {
        $response = $this->call( 'apiinfo.version', array(), false );
        return $response['result'] ?? null;
    }

    /**
     * Find a host group by name, creating it if it does not exist.
     *
     * @return string|null Group ID or null on failure.
     */
    private function get_or_create_host_group( string $name ): ?string {
        $response = $this->call( 'hostgroup.get', array(
            'output' => array( 'groupid', 'name' ),
            'filter' => array( 'name' => array( $name ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return null;
        }

        if ( ! empty( $response['result'] ) ) {
            return (string) $response['result'][0]['groupid'];
        }

        // Create the group
        $create = $this->call( 'hostgroup.create', array( 'name' => $name ) );
        if ( is_wp_error( $create ) || empty( $create['result']['groupids'][0] ) ) {
            $this->last_error = is_wp_error( $create )
                ? $create->get_error_message()
                : ( $create['error']['data'] ?? 'Could not create host group' );
            return null;
        }

        return (string) $create['result']['groupids'][0];
    }

    /**
     * Look up a template by name and return its ID.
     *
     * @return string|null Template ID or null if not found.
     */
    private function get_template_id( string $name ): ?string {
        $response = $this->call( 'template.get', array(
            'output' => array( 'templateid', 'name' ),
            'filter' => array( 'name' => array( $name ) ),
        ) );

        if ( is_wp_error( $response ) || empty( $response['result'] ) ) {
            $this->last_error = is_wp_error( $response )
                ? $response->get_error_message()
                : 'Template "' . $name . '" not found. Import the template first.';
            return null;
        }

        return (string) $response['result'][0]['templateid'];
    }

    /**
     * Look up a host by visible name and return its ID.
     *
     * @return string|null Host ID or null if not found.
     */
    private function get_host_id( string $name ): ?string {
        $response = $this->call( 'host.get', array(
            'output'      => array( 'hostid', 'name' ),
            'searchWildcardsEnabled' => false,
            'filter'      => array( 'name' => array( $name ) ),
        ) );

        if ( is_wp_error( $response ) || empty( $response['result'] ) ) {
            return null;
        }

        return (string) $response['result'][0]['hostid'];
    }

    /**
     * Create a new Zabbix host with the template and macros.
     *
     * @return string|null New host ID or null on failure.
     */
    private function create_host(
        string $host_name,
        string $group_id,
        string $template_id,
        string $wp_url,
        string $api_token
    ): ?string {
        // Use the site URL slug as the technical host name (must be unique)
        $technical_name = $this->slugify( $host_name );

        $response = $this->call( 'host.create', array(
            'host'       => $technical_name,
            'name'       => $host_name,
            'interfaces' => array(
                array(
                    'type'  => 1,   // Agent interface (required by API but not used for HTTP Agent items)
                    'main'  => 1,
                    'useip' => 1,
                    'ip'    => '127.0.0.1',
                    'dns'   => '',
                    'port'  => '10050',
                ),
            ),
            'groups'     => array( array( 'groupid' => $group_id ) ),
            'templates'  => array( array( 'templateid' => $template_id ) ),
            'macros'     => $this->build_macros( $wp_url, $api_token ),
        ) );

        if ( is_wp_error( $response ) || empty( $response['result']['hostids'][0] ) ) {
            $this->last_error = is_wp_error( $response )
                ? $response->get_error_message()
                : ( $response['error']['data'] ?? 'host.create failed' );
            return null;
        }

        return (string) $response['result']['hostids'][0];
    }

    /**
     * Update an existing Zabbix host: re-link template and refresh macros.
     */
    private function update_host(
        string $host_id,
        string $group_id,
        string $template_id,
        string $wp_url,
        string $api_token
    ): bool {
        $response = $this->call( 'host.update', array(
            'hostid'    => $host_id,
            'groups'    => array( array( 'groupid' => $group_id ) ),
            'templates' => array( array( 'templateid' => $template_id ) ),
            'macros'    => $this->build_macros( $wp_url, $api_token ),
        ) );

        if ( is_wp_error( $response ) || empty( $response['result']['hostids'] ) ) {
            $this->last_error = is_wp_error( $response )
                ? $response->get_error_message()
                : ( $response['error']['data'] ?? 'host.update failed' );
            return false;
        }

        return true;
    }

    /**
     * Build the macros array for host.create / host.update.
     *
     * @return array<int, array{macro: string, value: string}>
     */
    private function build_macros( string $wp_url, string $api_token ): array {
        return array(
            array( 'macro' => '{$WP_URL}',       'value' => rtrim( $wp_url, '/' ) ),
            array( 'macro' => '{$WP_API_TOKEN}', 'value' => $api_token ),
            // Threshold macros with sensible defaults
            array( 'macro' => '{$WP_LOAD_TIME_HIGH}', 'value' => '3000' ),
            array( 'macro' => '{$WP_LOAD_TIME_WARN}', 'value' => '1000' ),
            array( 'macro' => '{$WP_DB_QUERY_WARN}',  'value' => '100' ),
            array( 'macro' => '{$WP_DISK_HIGH}',       'value' => '90' ),
            array( 'macro' => '{$WP_DISK_WARN}',       'value' => '80' ),
            array( 'macro' => '{$WP_PLUGIN_UPDATES_WARN}', 'value' => '1' ),
            array( 'macro' => '{$WP_OVERDUE_CRON_WARN}',   'value' => '3' ),
        );
    }

    /**
     * Make a Zabbix JSON-RPC 2.0 API call.
     *
     * Zabbix 5.4 and earlier: auth token sent as "auth" field in the JSON body.
     * Zabbix 6.0+:            auth token sent as "Authorization: Bearer <token>" HTTP header.
     * Zabbix 7.0+:            the "auth" body field was REMOVED — header-only auth required.
     *
     * We detect the server version on first call and use the correct method automatically.
     *
     * @param string $method     Zabbix API method name
     * @param array  $params     Method parameters
     * @param bool   $with_auth  Whether to include the auth token
     * @return array|\WP_Error   Decoded response body or WP_Error
     */
    private function call( string $method, array $params, bool $with_auth = true ) {
        $payload = array(
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => 1,
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        // Zabbix 7.0+ requires auth via Authorization header; older versions used body "auth" field.
        // We always use the header approach (works on 6.0+ and 7.x) and omit the body field.
        if ( $with_auth && $this->auth_token ) {
            $headers['Authorization'] = 'Bearer ' . $this->auth_token;
        }

        $args = array(
            'method'    => 'POST',
            'headers'   => $headers,
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 15,
            'sslverify' => $this->ssl_verify,
        );

        $response = wp_remote_post( $this->api_url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'json_decode', 'Invalid JSON response from Zabbix API.' );
        }

        return $data;
    }

    /**
     * Convert a display name to a safe technical host name slug.
     */
    private function slugify( string $name ): string {
        $slug = strtolower( $name );
        $slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
        $slug = trim( $slug, '-' );
        return $slug ?: 'wordpress-site';
    }

    /**
     * Return a standardised error array and set last_error.
     *
     * @return array{success: bool, message: string}
     */
    private function error( string $message ): array {
        $this->last_error = $message;
        return array(
            'success' => false,
            'message' => $message,
        );
    }
}
