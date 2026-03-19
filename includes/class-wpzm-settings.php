<?php
/**
 * WPZM_Settings — manages plugin options with validation and sanitisation.
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Settings {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var array<string,mixed> */
    private array $options = array();

    // ─── Singleton ────────────────────────────────────────────────────────────

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = $this->load();
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Retrieve a single option value.
     *
     * @param string $key     Option key.
     * @param mixed  $default Fallback value.
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        return $this->options[ $key ] ?? $default;
    }

    /**
     * Return all options as an associative array.
     *
     * @return array<string,mixed>
     */
    public function all(): array {
        return $this->options;
    }

    /**
     * Sanitise settings. Called as WordPress sanitize_callback.
     *
     * IMPORTANT: sanitize_callback MUST return the sanitised value — WordPress
     * stores whatever this function returns as the option value. Returning true
     * or WP_Error would corrupt the stored option.
     *
     * @param array<string,mixed> $raw Raw POST data.
     * @return array<string,mixed> Sanitised settings array.
     */
    public function save( $raw ): array {
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        $sanitised     = $this->sanitise( $raw );
        $this->options = $sanitised;
        return $sanitised;
    }

    /**
     * Set a single option value and persist it.
     *
     * @param string $key   Option key.
     * @param mixed  $value New value.
     */
    public function set( string $key, $value ): void {
        $this->options[ $key ] = $value;
        update_option( WPZM_OPTION_KEY, $this->options );
    }

    /**
     * Regenerate the API token and persist it.
     *
     * @return string New token.
     */
    public function regenerate_token(): string {
        $token                  = wp_generate_password( 32, false );
        $this->options['api_token'] = $token;
        update_option( WPZM_OPTION_KEY, $this->options );
        return $token;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function load(): array {
        $saved    = get_option( WPZM_OPTION_KEY, array() );
        $defaults = $this->defaults();
        return wp_parse_args( $saved, $defaults );
    }

    /** @return array<string,mixed> */
    private function defaults(): array {
        return array(
            'api_token'       => '',
            'zabbix_server'   => '',
            'zabbix_port'     => 10051,
            'zabbix_host'     => '',
            'push_enabled'    => false,
            'push_interval'   => 60,
            'allowed_ips'     => '',
            'ssl_verify'      => true,
            'enabled_metrics' => array(
                'performance', 'database', 'users', 'content',
                'plugins', 'php', 'server', 'cron',
            ),
        );
    }

    /**
     * Sanitise raw input before saving.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function sanitise( array $raw ): array {
        $current = $this->options;

        // API token — keep existing if not explicitly changed.
        if ( ! empty( $raw['api_token'] ) ) {
            $current['api_token'] = sanitize_text_field( $raw['api_token'] );
        }

        if ( isset( $raw['zabbix_server'] ) ) {
            // Strip any URL scheme (https://, http://) and path — Zabbix Sender needs a
            // plain hostname or IP address, not a URL.
            $server = trim( $raw['zabbix_server'] );
            if ( preg_match( '#^https?://([^/]+)#i', $server, $m ) ) {
                $server = $m[1]; // extract hostname from URL
            }
            $current['zabbix_server'] = sanitize_text_field( $server );
        }

        if ( isset( $raw['zabbix_port'] ) ) {
            $port = (int) $raw['zabbix_port'];
            $current['zabbix_port'] = ( $port >= 1 && $port <= 65535 ) ? $port : 10051;
        }

        if ( isset( $raw['zabbix_host'] ) ) {
            $current['zabbix_host'] = sanitize_text_field( $raw['zabbix_host'] );
        }

        $current['push_enabled'] = ! empty( $raw['push_enabled'] );

        if ( isset( $raw['push_interval'] ) ) {
            $interval = (int) $raw['push_interval'];
            $current['push_interval'] = max( 30, min( 3600, $interval ) );
        }

        if ( isset( $raw['allowed_ips'] ) ) {
            $current['allowed_ips'] = sanitize_textarea_field( $raw['allowed_ips'] );
        }

        $current['ssl_verify'] = ! empty( $raw['ssl_verify'] );

        if ( isset( $raw['enabled_metrics'] ) && is_array( $raw['enabled_metrics'] ) ) {
            $allowed = array( 'performance', 'database', 'users', 'content', 'plugins', 'php', 'server', 'cron' );
            $current['enabled_metrics'] = array_values( array_intersect( $raw['enabled_metrics'], $allowed ) );
        }

        return $current;
    }
}
