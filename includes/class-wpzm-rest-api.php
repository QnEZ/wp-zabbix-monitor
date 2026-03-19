<?php
/**
 * WPZM_REST_API — registers the /wp-json/wpzm/v1/metrics endpoint.
 *
 * Authentication: Bearer token in the Authorization header, or
 *                 ?token=<api_token> query parameter.
 *
 * Optional IP allowlist: if configured, only requests from listed IPs
 * are accepted (useful for locking down to the Zabbix server IP).
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_REST_API {

    /** @var self|null */
    private static ?self $instance = null;

    /** REST namespace */
    const NAMESPACE = 'wpzm/v1';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    // ─── Route registration ───────────────────────────────────────────────────

    public function register_routes(): void {
        // Full metrics payload.
        register_rest_route( self::NAMESPACE, '/metrics', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_metrics' ),
            'permission_callback' => array( $this, 'check_permission' ),
            'args'                => array(
                'groups' => array(
                    'description'       => 'Comma-separated list of metric groups to return.',
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        // Single metric group.
        register_rest_route( self::NAMESPACE, '/metrics/(?P<group>[a-z_]+)', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_single_group' ),
            'permission_callback' => array( $this, 'check_permission' ),
            'args'                => array(
                'group' => array(
                    'description'       => 'Metric group name.',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ) );

        // Health check — returns 200 with minimal info (no auth required).
        register_rest_route( self::NAMESPACE, '/ping', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_ping' ),
            'permission_callback' => '__return_true',
        ) );
    }

    // ─── Handlers ─────────────────────────────────────────────────────────────

    /**
     * Return all (or selected) metric groups.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_metrics( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = WPZM_Settings::get_instance();
        $enabled  = $settings->get( 'enabled_metrics', array() );

        $param  = $request->get_param( 'groups' );
        $groups = $param ? array_map( 'trim', explode( ',', $param ) ) : $enabled;

        // Only allow enabled groups.
        $groups = array_intersect( $groups, $enabled );

        $metrics = WPZM_Metrics::get_instance()->collect( array_values( $groups ) );

        return rest_ensure_response( $metrics );
    }

    /**
     * Return a single metric group.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_single_group( \WP_REST_Request $request ) {
        $group    = $request->get_param( 'group' );
        $settings = WPZM_Settings::get_instance();
        $enabled  = $settings->get( 'enabled_metrics', array() );

        if ( ! in_array( $group, $enabled, true ) ) {
            return new \WP_Error(
                'wpzm_group_disabled',
                sprintf( __( 'Metric group "%s" is not enabled.', 'wp-zabbix-monitor' ), $group ),
                array( 'status' => 403 )
            );
        }

        $data = WPZM_Metrics::get_instance()->collect( array( $group ) );

        return rest_ensure_response( $data );
    }

    /**
     * Simple ping endpoint — returns plugin version and timestamp.
     *
     * @return \WP_REST_Response
     */
    public function handle_ping(): \WP_REST_Response {
        return rest_ensure_response( array(
            'status'     => 'ok',
            'plugin'     => 'wp-zabbix-monitor',
            'version'    => WPZM_VERSION,
            'timestamp'  => time(),
            'site_url'   => get_site_url(),
        ) );
    }

    // ─── Permission check ─────────────────────────────────────────────────────

    /**
     * Validate the API token and optional IP allowlist.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function check_permission( \WP_REST_Request $request ) {
        $settings = WPZM_Settings::get_instance();

        // ── IP allowlist ──────────────────────────────────────────────────────
        $allowed_ips_raw = $settings->get( 'allowed_ips', '' );
        if ( ! empty( $allowed_ips_raw ) ) {
            $allowed_ips = array_filter( array_map( 'trim', explode( "\n", $allowed_ips_raw ) ) );
            $remote_ip   = $this->get_remote_ip();
            if ( ! in_array( $remote_ip, $allowed_ips, true ) ) {
                return new \WP_Error(
                    'wpzm_ip_blocked',
                    __( 'Your IP address is not authorised.', 'wp-zabbix-monitor' ),
                    array( 'status' => 403 )
                );
            }
        }

        // ── Token check ───────────────────────────────────────────────────────
        $stored_token = $settings->get( 'api_token', '' );
        if ( empty( $stored_token ) ) {
            return new \WP_Error(
                'wpzm_no_token',
                __( 'API token is not configured.', 'wp-zabbix-monitor' ),
                array( 'status' => 500 )
            );
        }

        // Accept token via Authorization: Bearer <token> header.
        $auth_header = $request->get_header( 'authorization' );
        if ( $auth_header && preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $m ) ) {
            if ( hash_equals( $stored_token, $m[1] ) ) {
                return true;
            }
        }

        // Accept token via ?token= query parameter.
        $query_token = $request->get_param( 'token' );
        if ( $query_token && hash_equals( $stored_token, $query_token ) ) {
            return true;
        }

        return new \WP_Error(
            'wpzm_unauthorized',
            __( 'Invalid or missing API token.', 'wp-zabbix-monitor' ),
            array( 'status' => 401 )
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return string */
    private function get_remote_ip(): string {
        $candidates = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );
        foreach ( $candidates as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
