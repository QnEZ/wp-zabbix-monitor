<?php
/**
 * WPZM_Scheduler — manages the WP-Cron event that periodically pushes
 * metrics to the Zabbix server via the Zabbix Sender protocol.
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Scheduler {

    /** @var self|null */
    private static ?self $instance = null;

    /** Hook name used for the cron event */
    const HOOK = 'wpzm_push_metrics';

    /** Custom cron schedule identifier */
    const SCHEDULE_PREFIX = 'wpzm_every_';

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'cron_schedules',   array( $this, 'add_schedules' ) );
        add_action( self::HOOK,         array( $this, 'run_push' ) );

        // Re-schedule whenever settings are updated.
        add_action( 'update_option_' . WPZM_OPTION_KEY, array( $this, 'on_settings_update' ), 10, 2 );
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Schedule the push event at the given interval.
     *
     * @param int $interval_seconds Interval in seconds (30–3600).
     */
    public static function schedule_push( int $interval_seconds ): void {
        $interval_seconds = max( 30, min( 3600, $interval_seconds ) );
        $schedule_name    = self::SCHEDULE_PREFIX . $interval_seconds;

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), $schedule_name, self::HOOK );
        }
    }

    /**
     * Remove the scheduled push event.
     */
    public static function unschedule_push(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    // ─── Hooks ────────────────────────────────────────────────────────────────

    /**
     * Register custom cron intervals for every supported push interval.
     *
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function add_schedules( array $schedules ): array {
        $intervals = array( 30, 60, 120, 300, 600, 900, 1800, 3600 );
        foreach ( $intervals as $s ) {
            $key = self::SCHEDULE_PREFIX . $s;
            if ( ! isset( $schedules[ $key ] ) ) {
                $schedules[ $key ] = array(
                    'interval' => $s,
                    'display'  => sprintf(
                        /* translators: %d = number of seconds */
                        __( 'Every %d seconds (WP Zabbix Monitor)', 'wp-zabbix-monitor' ),
                        $s
                    ),
                );
            }
        }
        return $schedules;
    }

    /**
     * Execute the metric push. Called by WP-Cron.
     */
    public function run_push(): void {
        $settings = WPZM_Settings::get_instance();

        if ( ! $settings->get( 'push_enabled', false ) ) {
            return;
        }

        $result = WPZM_Sender::get_instance()->push();

        // Log result to WP debug log if enabled.
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf(
                '[WP Zabbix Monitor] Push result — success: %s, processed: %d, failed: %d, message: %s',
                $result['success'] ? 'yes' : 'no',
                $result['processed'],
                $result['failed'],
                $result['message']
            ) );
        }

        // Store last push result in a transient for the admin dashboard.
        set_transient( 'wpzm_last_push_result', $result, HOUR_IN_SECONDS );
    }

    /**
     * React to settings changes: reschedule or unschedule the cron event.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     */
    public function on_settings_update( $old_value, $new_value ): void {
        self::unschedule_push();

        if ( ! empty( $new_value['push_enabled'] ) ) {
            $interval = (int) ( $new_value['push_interval'] ?? 60 );
            self::schedule_push( $interval );
        }
    }
}
