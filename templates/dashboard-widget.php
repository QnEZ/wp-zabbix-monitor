<?php
/**
 * Dashboard widget template.
 *
 * Variables:
 *   $metrics   array       — collected metrics
 *   $last_push array|false — last push result
 *
 * @package WP_Zabbix_Monitor
 */
defined( 'ABSPATH' ) || exit;

$perf = $metrics['performance'] ?? array();
$db   = $metrics['database']    ?? array();
$usr  = $metrics['users']       ?? array();
$cron = $metrics['cron']        ?? array();
?>
<div class="wpzm-widget-grid">
    <div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'Load time', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( ( $perf['load_time_ms'] ?? '—' ) . ' ms' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'Memory', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( ( $perf['memory_usage_mb'] ?? '—' ) . ' MB' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'DB queries', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( $db['query_count'] ?? '—' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'DB size', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( ( $db['db_size_mb'] ?? '—' ) . ' MB' ); ?></span>
        </div>
    </div>
    <div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'Total users', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( $usr['total'] ?? '—' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'New users (24h)', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( $usr['new_24h'] ?? '—' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'Cron events', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value"><?php echo esc_html( $cron['total_events'] ?? '—' ); ?></span>
        </div>
        <div class="wpzm-widget-item">
            <span><?php esc_html_e( 'Overdue cron', 'wp-zabbix-monitor' ); ?></span>
            <span class="wpzm-widget-value <?php echo ( ( $cron['overdue_events'] ?? 0 ) > 0 ) ? 'wpzm-status error' : ''; ?>">
                <?php echo esc_html( $cron['overdue_events'] ?? '—' ); ?>
            </span>
        </div>
    </div>
</div>

<div class="wpzm-widget-footer">
    <span>
        <?php if ( $last_push ) : ?>
            <?php esc_html_e( 'Last push:', 'wp-zabbix-monitor' ); ?>
            <span class="wpzm-status <?php echo $last_push['success'] ? 'ok' : 'error'; ?>">
                <?php echo $last_push['success'] ? esc_html__( 'OK', 'wp-zabbix-monitor' ) : esc_html__( 'Failed', 'wp-zabbix-monitor' ); ?>
            </span>
        <?php else : ?>
            <?php esc_html_e( 'No push data yet.', 'wp-zabbix-monitor' ); ?>
        <?php endif; ?>
    </span>
    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-zabbix-monitor' ) ); ?>">
        <?php esc_html_e( 'Settings →', 'wp-zabbix-monitor' ); ?>
    </a>
</div>
