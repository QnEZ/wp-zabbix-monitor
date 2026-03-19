<?php
/**
 * WPZM_Admin — registers the WordPress admin settings page, dashboard widget,
 * and handles AJAX actions (manual push, token regeneration).
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Admin {

    /** @var self|null */
    private static ?self $instance = null;

    /** Admin page slug */
    const PAGE_SLUG = 'wp-zabbix-monitor';

    /** Settings section ID */
    const SECTION = 'wpzm_main_section';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            array( $this, 'add_menu_page' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_dashboard_setup',    array( $this, 'add_dashboard_widget' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_wpzm_manual_push',     array( $this, 'ajax_manual_push' ) );
        add_action( 'wp_ajax_wpzm_regen_token',     array( $this, 'ajax_regen_token' ) );
        add_action( 'wp_ajax_wpzm_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wpzm_get_metrics',     array( $this, 'ajax_get_metrics' ) );

        // Plugin action links.
        add_filter(
            'plugin_action_links_' . plugin_basename( WPZM_PLUGIN_FILE ),
            array( $this, 'plugin_action_links' )
        );
    }

    // ─── Menu & Settings ──────────────────────────────────────────────────────

    public function add_menu_page(): void {
        add_options_page(
            __( 'WP Zabbix Monitor', 'wp-zabbix-monitor' ),
            __( 'Zabbix Monitor', 'wp-zabbix-monitor' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings(): void {
        register_setting(
            self::PAGE_SLUG,
            WPZM_OPTION_KEY,
            array(
                'sanitize_callback' => array( WPZM_Settings::get_instance(), 'save' ),
                'default'           => array(),
            )
        );
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook && 'index.php' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wpzm-admin',
            WPZM_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            WPZM_VERSION
        );

        wp_enqueue_script(
            'wpzm-admin',
            WPZM_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            WPZM_VERSION,
            true
        );

        wp_localize_script( 'wpzm-admin', 'wpzmData', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wpzm_admin_nonce' ),
            'restBase'  => get_rest_url( null, 'wpzm/v1' ),
            'i18n'      => array(
                'pushing'     => __( 'Pushing…', 'wp-zabbix-monitor' ),
                'pushSuccess' => __( 'Push successful!', 'wp-zabbix-monitor' ),
                'pushFailed'  => __( 'Push failed.', 'wp-zabbix-monitor' ),
                'copied'      => __( 'Copied!', 'wp-zabbix-monitor' ),
                'testing'     => __( 'Testing…', 'wp-zabbix-monitor' ),
            ),
        ) );
    }

    // ─── Settings page render ─────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wp-zabbix-monitor' ) );
        }

        $settings   = WPZM_Settings::get_instance()->all();
        $last_push  = get_transient( 'wpzm_last_push_result' );
        $rest_url   = get_rest_url( null, 'wpzm/v1/metrics' );
        $ping_url   = get_rest_url( null, 'wpzm/v1/ping' );

        require_once WPZM_PLUGIN_DIR . 'templates/admin-page.php';
    }

    // ─── Dashboard widget ─────────────────────────────────────────────────────

    public function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wpzm_dashboard_widget',
            __( 'Zabbix Monitor — Site Health', 'wp-zabbix-monitor' ),
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget(): void {
        $metrics   = WPZM_Metrics::get_instance()->collect( array( 'performance', 'database', 'users', 'cron' ) );
        $last_push = get_transient( 'wpzm_last_push_result' );
        require_once WPZM_PLUGIN_DIR . 'templates/dashboard-widget.php';
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────────

    public function ajax_manual_push(): void {
        check_ajax_referer( 'wpzm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }

        $result = WPZM_Sender::get_instance()->push();
        set_transient( 'wpzm_last_push_result', $result, HOUR_IN_SECONDS );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_regen_token(): void {
        check_ajax_referer( 'wpzm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }

        $token = WPZM_Settings::get_instance()->regenerate_token();
        wp_send_json_success( array( 'token' => $token ) );
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'wpzm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }

        $settings = WPZM_Settings::get_instance();
        $server   = $settings->get( 'zabbix_server', '' );
        $port     = (int) $settings->get( 'zabbix_port', 10051 );

        if ( empty( $server ) ) {
            wp_send_json_error( array( 'message' => 'Zabbix server not configured.' ) );
        }

        // Send a single test item.
        $result = WPZM_Sender::get_instance()->send(
            $server,
            $port,
            $settings->get( 'zabbix_host', 'wordpress' ),
            array( 'wordpress.ping' => 1 )
        );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_get_metrics(): void {
        check_ajax_referer( 'wpzm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }

        $metrics = WPZM_Metrics::get_instance()->collect();
        wp_send_json_success( $metrics );
    }

    // ─── Plugin action links ──────────────────────────────────────────────────

    /**
     * @param string[] $links
     * @return string[]
     */
    public function plugin_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
            esc_html__( 'Settings', 'wp-zabbix-monitor' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }
}
