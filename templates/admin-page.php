<?php
/**
 * Admin settings page template.
 *
 * Variables available:
 *   $settings   array  — current plugin settings
 *   $last_push  array|false — last push result transient
 *   $rest_url   string — metrics REST endpoint URL
 *   $ping_url   string — ping REST endpoint URL
 *
 * @package WP_Zabbix_Monitor
 */
defined( 'ABSPATH' ) || exit;

$groups = array(
    'performance' => __( 'Performance', 'wp-zabbix-monitor' ),
    'database'    => __( 'Database', 'wp-zabbix-monitor' ),
    'users'       => __( 'Users', 'wp-zabbix-monitor' ),
    'content'     => __( 'Content', 'wp-zabbix-monitor' ),
    'plugins'     => __( 'Plugins', 'wp-zabbix-monitor' ),
    'php'         => __( 'PHP', 'wp-zabbix-monitor' ),
    'server'      => __( 'Server', 'wp-zabbix-monitor' ),
    'cron'        => __( 'Cron', 'wp-zabbix-monitor' ),
    'woocommerce' => __( 'WooCommerce', 'wp-zabbix-monitor' ),
    'matomo'      => __( 'Matomo Analytics', 'wp-zabbix-monitor' ),
);

$enabled_metrics = $settings['enabled_metrics'] ?? array_keys( $groups );
?>
<div class="wrap wpzm-wrap">

    <div class="wpzm-header">
        <h1><?php esc_html_e( 'WP Zabbix Monitor', 'wp-zabbix-monitor' ); ?></h1>
        <span class="wpzm-badge">v<?php echo esc_html( WPZM_VERSION ); ?></span>
    </div>

    <?php settings_errors( WPZM_OPTION_KEY ); ?>

    <!-- Tabs -->
    <div class="wpzm-tabs">
        <button class="wpzm-tab" data-tab="connection"><?php esc_html_e( 'Zabbix Connection', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab" data-tab="api"><?php esc_html_e( 'REST API', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab" data-tab="metrics"><?php esc_html_e( 'Metrics', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab" data-tab="matomo"><?php esc_html_e( 'Matomo', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab" data-tab="live"><?php esc_html_e( 'Live Data', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab" data-tab="updates"><?php esc_html_e( 'Updates', 'wp-zabbix-monitor' ); ?></button>
        <button class="wpzm-tab wpzm-tab-provision" data-tab="provision"><?php esc_html_e( '⚡ Auto-Provision', 'wp-zabbix-monitor' ); ?></button>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields( WPZM_Admin::PAGE_SLUG ); ?>

        <!-- ── Tab: Zabbix Connection ──────────────────────────────────────── -->
        <div id="wpzm-tab-connection" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-networking"></span>
                    <?php esc_html_e( 'Zabbix Server Settings', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'Configure the Zabbix server that will receive metric data pushed by this plugin via the Zabbix Sender protocol (TCP port 10051).', 'wp-zabbix-monitor' ); ?>
                </p>

                <table class="form-table wpzm-form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpzm-zabbix-server"><?php esc_html_e( 'Zabbix Server Host', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wpzm-zabbix-server"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[zabbix_server]"
                                   value="<?php echo esc_attr( $settings['zabbix_server'] ?? '' ); ?>"
                                   placeholder="zabbix.example.com or 192.168.1.100"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Hostname or IP address only — e.g. z.qnez.net or 192.168.1.100. Do not include https:// or a path. If you paste a full URL the scheme and path will be stripped automatically.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpzm-zabbix-port"><?php esc_html_e( 'Zabbix Sender Port', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="wpzm-zabbix-port"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[zabbix_port]"
                                   value="<?php echo esc_attr( $settings['zabbix_port'] ?? 10051 ); ?>"
                                   min="1" max="65535" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Default: 10051. Change only if your Zabbix server uses a non-standard port.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpzm-zabbix-host"><?php esc_html_e( 'Zabbix Host Name', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wpzm-zabbix-host"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[zabbix_host]"
                                   value="<?php echo esc_attr( $settings['zabbix_host'] ?? '' ); ?>"
                                   placeholder="my-wordpress-site"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e( 'The host name exactly as configured in Zabbix (Data collection → Hosts).', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Push Metrics (Active)', 'wp-zabbix-monitor' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wpzm-push-enabled"
                                       name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[push_enabled]"
                                       value="1"
                                       <?php checked( ! empty( $settings['push_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Enable periodic metric push to Zabbix (Zabbix Sender / Trapper items)', 'wp-zabbix-monitor' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="wpzm-push-settings">
                        <th scope="row">
                            <label for="wpzm-push-interval"><?php esc_html_e( 'Push Interval', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <select id="wpzm-push-interval"
                                    name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[push_interval]">
                                <?php
                                $intervals = array( 30 => '30s', 60 => '1m', 120 => '2m', 300 => '5m', 600 => '10m', 900 => '15m', 1800 => '30m', 3600 => '1h' );
                                foreach ( $intervals as $val => $label ) :
                                    ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"
                                        <?php selected( (int) ( $settings['push_interval'] ?? 60 ), $val ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'How often to push metrics to Zabbix via WP-Cron.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Connection test & manual push -->
            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-controls-play"></span>
                    <?php esc_html_e( 'Actions', 'wp-zabbix-monitor' ); ?>
                </h2>

                <?php if ( $last_push ) : ?>
                    <p>
                        <?php esc_html_e( 'Last push:', 'wp-zabbix-monitor' ); ?>
                        <span class="wpzm-status <?php echo $last_push['success'] ? 'ok' : 'error'; ?>">
                            <?php echo $last_push['success'] ? esc_html__( 'Success', 'wp-zabbix-monitor' ) : esc_html__( 'Failed', 'wp-zabbix-monitor' ); ?>
                        </span>
                        — <?php echo esc_html( $last_push['message'] ); ?>
                    </p>
                <?php endif; ?>

                <div class="wpzm-action-bar">
                    <button type="button" id="wpzm-test-connection" class="button">
                        <?php esc_html_e( 'Test Connection', 'wp-zabbix-monitor' ); ?>
                    </button>
                    <button type="button" id="wpzm-push-btn" class="button button-primary">
                        <?php esc_html_e( 'Push Now', 'wp-zabbix-monitor' ); ?>
                    </button>
                </div>

                <div id="wpzm-connection-result" class="wpzm-push-result" style="margin-top:10px;"></div>
                <div id="wpzm-push-result" style="margin-top:10px;"></div>
            </div>

            <?php submit_button( __( 'Save Connection Settings', 'wp-zabbix-monitor' ) ); ?>
        </div>

        <!-- ── Tab: REST API ───────────────────────────────────────────────── -->
        <div id="wpzm-tab-api" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-rest-api"></span>
                    <?php esc_html_e( 'REST API Endpoint (HTTP Agent / Pull Mode)', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'Zabbix can poll this endpoint using HTTP Agent items. Add the token as a Bearer header or ?token= query parameter.', 'wp-zabbix-monitor' ); ?>
                </p>

                <p><strong><?php esc_html_e( 'Metrics endpoint:', 'wp-zabbix-monitor' ); ?></strong></p>
                <div class="wpzm-endpoint">
                    <span class="wpzm-endpoint-text"><?php echo esc_url( $rest_url ); ?></span>
                    <button type="button" class="wpzm-copy-btn" title="<?php esc_attr_e( 'Copy', 'wp-zabbix-monitor' ); ?>">⎘</button>
                </div>

                <p><strong><?php esc_html_e( 'Ping endpoint (no auth):', 'wp-zabbix-monitor' ); ?></strong></p>
                <div class="wpzm-endpoint">
                    <span class="wpzm-endpoint-text"><?php echo esc_url( $ping_url ); ?></span>
                    <button type="button" class="wpzm-copy-btn" title="<?php esc_attr_e( 'Copy', 'wp-zabbix-monitor' ); ?>">⎘</button>
                </div>

                <p><strong><?php esc_html_e( 'Example curl:', 'wp-zabbix-monitor' ); ?></strong></p>
                <div class="wpzm-endpoint">
                    <span class="wpzm-endpoint-text">curl -H "Authorization: Bearer <?php echo esc_html( $settings['api_token'] ?? 'YOUR_TOKEN' ); ?>" "<?php echo esc_url( $rest_url ); ?>"</span>
                </div>
            </div>

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-lock"></span>
                    <?php esc_html_e( 'API Security', 'wp-zabbix-monitor' ); ?>
                </h2>

                <table class="form-table wpzm-form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpzm-api-token"><?php esc_html_e( 'API Token', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <div class="wpzm-token-wrap">
                                <input type="text" id="wpzm-api-token"
                                       name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[api_token]"
                                       value="<?php echo esc_attr( $settings['api_token'] ?? '' ); ?>"
                                       class="regular-text" readonly />
                                <button type="button" id="wpzm-regen-token" class="button">
                                    <?php esc_html_e( 'Regenerate', 'wp-zabbix-monitor' ); ?>
                                </button>
                                <button type="button" class="wpzm-copy-btn" title="<?php esc_attr_e( 'Copy token', 'wp-zabbix-monitor' ); ?>">⎘</button>
                            </div>
                            <p class="description"><?php esc_html_e( 'Use this token in the Authorization: Bearer header when configuring Zabbix HTTP Agent items.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpzm-allowed-ips"><?php esc_html_e( 'IP Allowlist', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <textarea id="wpzm-allowed-ips"
                                      name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[allowed_ips]"
                                      rows="4" class="large-text"
                                      placeholder="192.168.1.100&#10;10.0.0.50"><?php echo esc_textarea( $settings['allowed_ips'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One IP per line. Leave blank to allow all IPs (token still required). Recommended: add your Zabbix server IP.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( __( 'Save API Settings', 'wp-zabbix-monitor' ) ); ?>
        </div>

        <!-- ── Tab: Metrics ────────────────────────────────────────────────── -->
        <div id="wpzm-tab-metrics" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-chart-line"></span>
                    <?php esc_html_e( 'Enabled Metric Groups', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'Select which metric categories are collected and exposed. Disabling a group removes it from both the REST API response and the Zabbix push payload.', 'wp-zabbix-monitor' ); ?>
                </p>

                <div class="wpzm-checkbox-group" style="margin-top:16px;">
                    <?php foreach ( $groups as $key => $label ) : ?>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[enabled_metrics][]"
                                   value="<?php echo esc_attr( $key ); ?>"
                                   <?php checked( in_array( $key, $enabled_metrics, true ) ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php submit_button( __( 'Save Metric Settings', 'wp-zabbix-monitor' ) ); ?>
        </div>

        <!-- ── Tab: Matomo ────────────────────────────────────────────────── -->
        <div id="wpzm-tab-matomo" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e( 'Matomo Analytics Integration', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'Connect to your self-hosted Matomo instance to collect traffic and engagement metrics alongside WordPress performance data.', 'wp-zabbix-monitor' ); ?>
                </p>

                <table class="form-table wpzm-form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpzm-matomo-url"><?php esc_html_e( 'Matomo URL', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="url" id="wpzm-matomo-url"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[matomo_url]"
                                   value="<?php echo esc_attr( $settings['matomo_url'] ?? '' ); ?>"
                                   placeholder="https://stats.example.com"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Full URL to your Matomo installation (e.g., https://stats.qnez.net). Must be accessible from this WordPress server.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpzm-matomo-token"><?php esc_html_e( 'API Token', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="wpzm-matomo-token"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[matomo_token]"
                                   value="<?php echo esc_attr( $settings['matomo_token'] ?? '' ); ?>"
                                   placeholder="Your Matomo API token"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Generate this in Matomo: Settings → Personal → API Tokens. Use a token with at least View access to your site.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpzm-matomo-site-id"><?php esc_html_e( 'Site ID', 'wp-zabbix-monitor' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="wpzm-matomo-site-id"
                                   name="<?php echo esc_attr( WPZM_OPTION_KEY ); ?>[matomo_site_id]"
                                   value="<?php echo esc_attr( $settings['matomo_site_id'] ?? 1 ); ?>"
                                   min="1" class="small-text" />
                            <p class="description"><?php esc_html_e( 'The Matomo site ID for this WordPress installation. Usually 1 for the first site. Find it in Matomo: Administration → Websites.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpzm-card">
                <h2><?php esc_html_e( 'Matomo Metrics Collected', 'wp-zabbix-monitor' ); ?></h2>
                <p class="description"><?php esc_html_e( 'When enabled in the Metrics tab, the following Matomo data is collected every push cycle:', 'wp-zabbix-monitor' ); ?></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e( 'Site Level:', 'wp-zabbix-monitor' ); ?></strong>
                        <ul style="margin-left: 20px;">
                            <li><?php esc_html_e( 'Total pageviews (today)', 'wp-zabbix-monitor' ); ?></li>
                            <li><?php esc_html_e( 'Unique visitors (today)', 'wp-zabbix-monitor' ); ?></li>
                            <li><?php esc_html_e( 'Bounce rate (%)', 'wp-zabbix-monitor' ); ?></li>
                            <li><?php esc_html_e( 'Average session duration (seconds)', 'wp-zabbix-monitor' ); ?></li>
                        </ul>
                    </li>
                    <li><strong><?php esc_html_e( 'Traffic Sources:', 'wp-zabbix-monitor' ); ?></strong>
                        <ul style="margin-left: 20px;">
                            <li><?php esc_html_e( 'Direct visits', 'wp-zabbix-monitor' ); ?></li>
                            <li><?php esc_html_e( 'Organic search', 'wp-zabbix-monitor' ); ?></li>
                            <li><?php esc_html_e( 'Referral traffic', 'wp-zabbix-monitor' ); ?></li>
                        </ul>
                    </li>
                    <li><strong><?php esc_html_e( 'Top Pages:', 'wp-zabbix-monitor' ); ?></strong>
                        <ul style="margin-left: 20px;">
                            <li><?php esc_html_e( 'Visit counts for top 5 most-visited pages', 'wp-zabbix-monitor' ); ?></li>
                        </ul>
                    </li>
                </ul>
            </div>

            <?php submit_button( __( 'Save Matomo Settings', 'wp-zabbix-monitor' ) ); ?>
        </div>

        <!-- ── Tab: Live Data ─────────────────────────────────────────────── -->
        <div id="wpzm-tab-live" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-dashboard"></span>
                    <?php esc_html_e( 'Live Site Metrics', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'Current values collected from this WordPress installation. Click Refresh to update.', 'wp-zabbix-monitor' ); ?>
                </p>

                <div class="wpzm-action-bar" style="margin-bottom:16px;">
                    <button type="button" id="wpzm-refresh-metrics" class="button">
                        <?php esc_html_e( 'Refresh', 'wp-zabbix-monitor' ); ?>
                    </button>
                </div>

                <?php
                $live = WPZM_Metrics::get_instance()->collect( $enabled_metrics );
                ?>

                <div class="wpzm-metrics-grid">
                    <?php if ( isset( $live['performance'] ) ) : $p = $live['performance']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Load Time', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="performance.load_time_ms"><?php echo esc_html( $p['load_time_ms'] ?? '—' ); ?></span>
                                <span class="wpzm-metric-unit">ms</span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Memory Usage', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="performance.memory_usage_mb"><?php echo esc_html( $p['memory_usage_mb'] ?? '—' ); ?></span>
                                <span class="wpzm-metric-unit">MB</span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Peak Memory', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="performance.memory_peak_mb"><?php echo esc_html( $p['memory_peak_mb'] ?? '—' ); ?></span>
                                <span class="wpzm-metric-unit">MB</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['database'] ) ) : $d = $live['database']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'DB Queries', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="database.query_count"><?php echo esc_html( $d['query_count'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'DB Size', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="database.db_size_mb"><?php echo esc_html( $d['db_size_mb'] ?? '—' ); ?></span>
                                <span class="wpzm-metric-unit">MB</span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Slow Queries', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="database.slow_queries"><?php echo esc_html( $d['slow_queries'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['users'] ) ) : $u = $live['users']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Total Users', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="users.total"><?php echo esc_html( $u['total'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'New Users (24h)', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="users.new_24h"><?php echo esc_html( $u['new_24h'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['content'] ) ) : $c = $live['content']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Published Posts', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="content.published_posts"><?php echo esc_html( $c['published_posts'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Pending Comments', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="content.comments_pending"><?php echo esc_html( $c['comments_pending'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['plugins'] ) ) : $pl = $live['plugins']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Plugin Updates', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="plugins.needs_update"><?php echo esc_html( $pl['needs_update'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['cron'] ) ) : $cr = $live['cron']; ?>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Overdue Cron Jobs', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="cron.overdue_events"><?php echo esc_html( $cr['overdue_events'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( isset( $live['woocommerce'] ) && ! empty( $live['woocommerce'] ) ) : $wc = $live['woocommerce']; ?>
                        <div class="wpzm-metric-card" style="grid-column:1/-1;background:#f0f4ff;border-color:#c3d0f5;">
                            <div class="wpzm-metric-label" style="color:#2271b1;font-weight:700;font-size:12px;"><?php esc_html_e( '🛒 WooCommerce', 'wp-zabbix-monitor' ); ?></div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Orders Today', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.orders_total_today"><?php echo esc_html( $wc['orders_total_today'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Orders/Hour', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.orders_per_hour"><?php echo esc_html( $wc['orders_per_hour'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Revenue Today', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.revenue_today"><?php echo esc_html( $wc['revenue_today'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Pending Orders', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.orders_pending"><?php echo esc_html( $wc['orders_pending'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Out of Stock', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.products_out_of_stock"><?php echo esc_html( $wc['products_out_of_stock'] ?? '—' ); ?></span>
                            </div>
                        </div>
                        <div class="wpzm-metric-card">
                            <div class="wpzm-metric-label"><?php esc_html_e( 'Low Stock', 'wp-zabbix-monitor' ); ?></div>
                            <div class="wpzm-metric-value">
                                <span data-metric="woocommerce.products_low_stock"><?php echo esc_html( $wc['products_low_stock'] ?? '—' ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

           </div><!-- /live tab -->

        <!-- ── Tab: Updates ────────────────────────────────────────────────── -->
        <div id="wpzm-tab-updates" class="wpzm-tab-content">

            <div class="wpzm-card">
                <h2><span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Plugin Updates', 'wp-zabbix-monitor' ); ?>
                </h2>
                <p class="description">
                    <?php esc_html_e( 'WP Zabbix Monitor checks GitHub for new releases automatically. Updates are displayed in the Plugins page and can be installed with one click.', 'wp-zabbix-monitor' ); ?>
                </p>

                <table class="form-table wpzm-form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Current Version', 'wp-zabbix-monitor' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( WPZM_VERSION ); ?></strong>
                            <p class="description"><?php esc_html_e( 'You are running the latest version.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Update Source', 'wp-zabbix-monitor' ); ?></th>
                        <td>
                            <strong><?php esc_html_e( 'GitHub Releases', 'wp-zabbix-monitor' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'Updates are fetched from: ', 'wp-zabbix-monitor' ); ?><a href="https://github.com/QnEZ/wp-zabbix-monitor/releases" target="_blank" rel="noopener">github.com/QnEZ/wp-zabbix-monitor/releases</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Check Frequency', 'wp-zabbix-monitor' ); ?></th>
                        <td>
                            <strong><?php esc_html_e( 'Every 12 hours', 'wp-zabbix-monitor' ); ?></strong>
                            <p class="description"><?php esc_html_e( 'WordPress checks for updates automatically. Updates are cached for 12 hours to reduce API calls.', 'wp-zabbix-monitor' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpzm-card">
                <h2><?php esc_html_e( 'How Updates Work', 'wp-zabbix-monitor' ); ?></h2>
                <ol style="margin-left: 20px; line-height: 1.8;">
                    <li><?php esc_html_e( 'WordPress checks GitHub for new releases every 12 hours', 'wp-zabbix-monitor' ); ?></li>
                    <li><?php esc_html_e( 'If a new version is available, an "Update Available" badge appears in the Plugins page', 'wp-zabbix-monitor' ); ?></li>
                    <li><?php esc_html_e( 'Click "Update Now" to download and install the update', 'wp-zabbix-monitor' ); ?></li>
                    <li><?php esc_html_e( 'WordPress automatically backs up your site before updating', 'wp-zabbix-monitor' ); ?></li>
                    <li><?php esc_html_e( 'If the update fails, WordPress can roll back to the previous version', 'wp-zabbix-monitor' ); ?></li>
                </ol>
            </div>

            <div class="wpzm-card">
                <h2><?php esc_html_e( 'Release Information', 'wp-zabbix-monitor' ); ?></h2>
                <p class="description"><?php esc_html_e( 'View the changelog and release notes on GitHub:', 'wp-zabbix-monitor' ); ?></p>
                <p>
                    <a href="https://github.com/QnEZ/wp-zabbix-monitor/releases" class="button" target="_blank" rel="noopener">
                        <?php esc_html_e( 'View All Releases', 'wp-zabbix-monitor' ); ?>
                    </a>
                </p>
            </div>
        </div><!-- /updates tab -->

        <!-- ── Tab: Auto-Provisionvision (outside form — uses AJAX only) ──────────── -->
    <div id="wpzm-tab-provision" class="wpzm-tab-content">

        <?php
        $prov_last_run  = $settings['provision_last_run']  ?? 0;
        $prov_host_id   = $settings['provision_host_id']   ?? '';
        $prov_host_name = $settings['provision_host_name'] ?? '';
        $prov_api_url   = $settings['provision_api_url']   ?? '';
        $prov_username  = $settings['provision_username']  ?? '';
        ?>

        <div class="wpzm-card">
            <h2><span class="dashicons dashicons-cloud-upload"></span>
                <?php esc_html_e( 'Zabbix API Auto-Provisioning', 'wp-zabbix-monitor' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e(
                    'Automatically create (or update) a Zabbix host for this WordPress site using the Zabbix JSON-RPC API. '
                    . 'The plugin will locate the imported template, create the host group if needed, and set the {$WP_URL} and {$WP_API_TOKEN} macros automatically.',
                    'wp-zabbix-monitor'
                ); ?>
            </p>

            <?php if ( $prov_host_id ) : ?>
                <div class="wpzm-provision-status ok">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php printf(
                        esc_html__( 'Last provisioned: host "%1$s" (ID: %2$s) on %3$s', 'wp-zabbix-monitor' ),
                        esc_html( $prov_host_name ),
                        esc_html( $prov_host_id ),
                        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $prov_last_run ) )
                    ); ?>
                </div>
            <?php endif; ?>

            <table class="form-table wpzm-form-table" style="margin-top:16px;">
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-api-url"><?php esc_html_e( 'Zabbix API URL', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="wpzm-prov-api-url"
                               value="<?php echo esc_attr( $prov_api_url ); ?>"
                               placeholder="https://zabbix.example.com/api_jsonrpc.php"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Full URL to the Zabbix JSON-RPC endpoint (usually /api_jsonrpc.php).', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-username"><?php esc_html_e( 'Zabbix Username', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpzm-prov-username"
                               value="<?php echo esc_attr( $prov_username ); ?>"
                               placeholder="Admin"
                               class="regular-text" autocomplete="username" />
                        <p class="description"><?php esc_html_e( 'Zabbix frontend user with at least Host write permission.', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-password"><?php esc_html_e( 'Zabbix Password', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="wpzm-prov-password"
                               placeholder="••••••••"
                               class="regular-text" autocomplete="current-password" />
                        <p class="description"><?php esc_html_e( 'Password is sent over HTTPS and never stored in the database.', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-host-name"><?php esc_html_e( 'Host Display Name', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpzm-prov-host-name"
                               value="<?php echo esc_attr( $prov_host_name ?: get_bloginfo( 'name' ) ); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Visible name for the host in Zabbix. Defaults to the site name.', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-host-group"><?php esc_html_e( 'Host Group', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpzm-prov-host-group"
                               value="WordPress Sites"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Zabbix host group to assign. Created automatically if it does not exist.', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wpzm-prov-template"><?php esc_html_e( 'Template Name', 'wp-zabbix-monitor' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wpzm-prov-template"
                               value="WordPress by WP Zabbix Monitor"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Exact name of the imported Zabbix template. Must be imported before provisioning.', 'wp-zabbix-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Verify SSL', 'wp-zabbix-monitor' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wpzm-prov-ssl" checked />
                            <?php esc_html_e( 'Verify Zabbix server SSL certificate', 'wp-zabbix-monitor' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wpzm-card">
            <h2><span class="dashicons dashicons-controls-play"></span>
                <?php esc_html_e( 'Provisioning Actions', 'wp-zabbix-monitor' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Test your credentials first, then run provisioning. Running provisioning on an existing host will update its template link and macros without deleting historical data.', 'wp-zabbix-monitor' ); ?>
            </p>

            <div class="wpzm-action-bar">
                <button type="button" id="wpzm-test-api-conn" class="button">
                    <?php esc_html_e( 'Test API Connection', 'wp-zabbix-monitor' ); ?>
                </button>
                <button type="button" id="wpzm-run-provision" class="button button-primary">
                    <?php esc_html_e( 'Provision Host in Zabbix', 'wp-zabbix-monitor' ); ?>
                </button>
            </div>

            <div id="wpzm-provision-result" style="margin-top:12px;"></div>
        </div>

    </div><!-- /provision tab -->

</div>
