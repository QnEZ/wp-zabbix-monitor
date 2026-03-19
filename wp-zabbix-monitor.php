<?php
/**
 * Plugin Name:       WP Zabbix Monitor
 * Plugin URI:        https://github.com/your-org/wp-zabbix-monitor
 * Description:       Full-stack WordPress monitoring plugin that exposes site metrics via a secured REST API endpoint and pushes data to a Zabbix server using the Zabbix Sender protocol. Includes a ready-to-import Zabbix template with items, triggers, and graphs.
 * Version:           1.2.12
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            QnEZ Servers
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-zabbix-monitor
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'WPZM_VERSION',     '1.2.0' );
define( 'WPZM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WPZM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WPZM_PLUGIN_FILE', __FILE__ );
define( 'WPZM_OPTION_KEY',  'wpzm_settings' );

// ─── Autoload includes ────────────────────────────────────────────────────────
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-settings.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-metrics.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-rest-api.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-sender.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-scheduler.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-woocommerce.php';
require_once WPZM_PLUGIN_DIR . 'includes/class-wpzm-provisioner.php';
require_once WPZM_PLUGIN_DIR . 'admin/class-wpzm-admin.php';

// ─── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( 'WPZM_Settings',   'get_instance' ) );
add_action( 'plugins_loaded', array( 'WPZM_REST_API',   'get_instance' ) );
add_action( 'plugins_loaded', array( 'WPZM_Scheduler',  'get_instance' ) );

if ( is_admin() ) {
    add_action( 'plugins_loaded', array( 'WPZM_Admin', 'get_instance' ) );
}

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook(   __FILE__, 'wpzm_activate' );
register_deactivation_hook( __FILE__, 'wpzm_deactivate' );

/**
 * Plugin activation: set default options and schedule the push cron.
 */
function wpzm_activate(): void {
    $defaults = array(
        'api_token'         => wp_generate_password( 32, false ),
        'zabbix_server'     => '',
        'zabbix_port'       => 10051,
        'zabbix_host'       => '',
        'push_enabled'      => false,
        'push_interval'     => 60,   // seconds
        'allowed_ips'       => '',
        'ssl_verify'        => true,
        'enabled_metrics'   => array(
            'performance', 'database', 'users', 'content',
            'plugins', 'php', 'server', 'cron', 'woocommerce',
        ),
    );

    if ( ! get_option( WPZM_OPTION_KEY ) ) {
        add_option( WPZM_OPTION_KEY, $defaults );
    }

    // Schedule the push event if push is enabled.
    $settings = get_option( WPZM_OPTION_KEY, $defaults );
    if ( ! empty( $settings['push_enabled'] ) ) {
        WPZM_Scheduler::schedule_push( (int) $settings['push_interval'] );
    }

    flush_rewrite_rules();
}

/**
 * Plugin deactivation: clear scheduled events.
 */
function wpzm_deactivate(): void {
    wp_clear_scheduled_hook( 'wpzm_push_metrics' );
    flush_rewrite_rules();
}
