<?php
/**
 * WPZM_Metrics — collects WordPress site metrics across multiple categories.
 *
 * Categories:
 *   performance  — page load time, TTFB, WP memory, peak memory
 *   database     — query count, total query time, slow queries, DB size
 *   users        — total users, active sessions, new users (24h), admins
 *   content      — posts, pages, comments (approved/pending/spam), media
 *   plugins      — total, active, inactive, update-available count
 *   php          — version, memory limit, max execution time, opcache
 *   server       — PHP SAPI, OS, server software, disk free/total
 *   cron         — next scheduled event, overdue events count
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Metrics {

    /** @var self|null */
    private static ?self $instance = null;

    /** Singleton accessor */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Collect all enabled metric groups.
     *
     * @param string[] $groups List of group keys to collect.
     * @return array<string,array<string,mixed>>
     */
    public function collect( array $groups = array() ): array {
        $all_groups = array(
            'performance' => array( $this, 'performance' ),
            'database'    => array( $this, 'database' ),
            'users'       => array( $this, 'users' ),
            'content'     => array( $this, 'content' ),
            'plugins'     => array( $this, 'plugins' ),
            'php'         => array( $this, 'php_info' ),
            'server'      => array( $this, 'server' ),
            'cron'        => array( $this, 'cron' ),
            'woocommerce' => array( $this, 'woocommerce' ),
        );

        if ( empty( $groups ) ) {
            $groups = array_keys( $all_groups );
        }

        $result = array(
            'timestamp'   => time(),
            'site_url'    => get_site_url(),
            'wp_version'  => get_bloginfo( 'version' ),
        );

        foreach ( $groups as $key ) {
            if ( isset( $all_groups[ $key ] ) ) {
                try {
                    $result[ $key ] = call_user_func( $all_groups[ $key ] );
                } catch ( \Throwable $e ) {
                    $result[ $key ] = array( 'error' => $e->getMessage() );
                }
            }
        }

        return $result;
    }

    /**
     * Flatten collected metrics into a key→value map suitable for Zabbix sender.
     * Keys follow the pattern: wordpress.<group>.<metric>
     *
     * @param array<string,mixed> $data Output of collect().
     * @return array<string,int|float|string>
     */
    public function flatten( array $data ): array {
        $flat = array();
        foreach ( $data as $group => $values ) {
            if ( ! is_array( $values ) ) {
                continue;
            }
            foreach ( $values as $metric => $value ) {
                if ( is_scalar( $value ) ) {
                    $flat[ "wordpress.{$group}.{$metric}" ] = $value;
                }
            }
        }
        return $flat;
    }

    // ─── Metric groups ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function performance(): array {
        global $timestart;

        $elapsed_ms = isset( $timestart )
            ? round( ( microtime( true ) - $timestart ) * 1000, 2 )
            : 0.0;

        return array(
            'load_time_ms'      => $elapsed_ms,
            'memory_usage_mb'   => round( memory_get_usage( true ) / 1048576, 2 ),
            'memory_peak_mb'    => round( memory_get_peak_usage( true ) / 1048576, 2 ),
            'memory_limit_mb'   => (int) ini_get( 'memory_limit' ),
            'wp_memory_limit_mb'=> (int) WP_MEMORY_LIMIT,
        );
    }

    /** @return array<string,mixed> */
    public function database(): array {
        global $wpdb;

        $query_count  = get_num_queries();
        $query_time   = 0.0;
        $slow_queries = 0;
        $slow_threshold = 0.05; // 50 ms

        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
            foreach ( $wpdb->queries as $q ) {
                $t = isset( $q[1] ) ? (float) $q[1] : 0.0;
                $query_time += $t;
                if ( $t > $slow_threshold ) {
                    $slow_queries++;
                }
            }
        }

        // Database size in MB.
        $db_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND(SUM(data_length + index_length) / 1048576, 2)
                 FROM information_schema.TABLES
                 WHERE table_schema = %s",
                DB_NAME
            )
        );

        // Autoload options size in KB.
        $autoload_size = $wpdb->get_var(
            "SELECT ROUND(SUM(LENGTH(option_value)) / 1024, 2)
             FROM {$wpdb->options}
             WHERE autoload = 'yes'"
        );

        return array(
            'query_count'       => (int) $query_count,
            'query_time_ms'     => round( $query_time * 1000, 2 ),
            'slow_queries'      => (int) $slow_queries,
            'db_size_mb'        => (float) ( $db_size ?? 0 ),
            'autoload_size_kb'  => (float) ( $autoload_size ?? 0 ),
        );
    }

    /** @return array<string,mixed> */
    public function users(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

        // New users in the last 24 hours.
        $new_24h = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s",
                gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
            )
        );

        // Admin count.
        $admin_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value LIKE %s",
                $wpdb->prefix . 'capabilities',
                '%administrator%'
            )
        );

        // Active sessions (users with a valid auth cookie in the last hour).
        $active_sessions = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
                 WHERE meta_key = 'session_tokens'
                 AND meta_value != '' "
            )
        );

        return array(
            'total'          => $total,
            'new_24h'        => $new_24h,
            'admin_count'    => $admin_count,
            'active_sessions'=> $active_sessions,
        );
    }

    /** @return array<string,mixed> */
    public function content(): array {
        global $wpdb;

        $posts    = (int) wp_count_posts( 'post' )->publish;
        $pages    = (int) wp_count_posts( 'page' )->publish;
        $drafts   = (int) wp_count_posts( 'post' )->draft;
        $comments = wp_count_comments();
        $media    = (int) wp_count_posts( 'attachment' )->inherit;

        // Custom post type count (non-built-in).
        $cpt_types = get_post_types( array( '_builtin' => false, 'public' => true ) );
        $cpt_count = 0;
        foreach ( $cpt_types as $type ) {
            $counts = wp_count_posts( $type );
            $cpt_count += isset( $counts->publish ) ? (int) $counts->publish : 0;
        }

        return array(
            'published_posts'    => $posts,
            'published_pages'    => $pages,
            'draft_posts'        => $drafts,
            'custom_post_types'  => $cpt_count,
            'media_files'        => $media,
            'comments_approved'  => (int) $comments->approved,
            'comments_pending'   => (int) $comments->moderated,
            'comments_spam'      => (int) $comments->spam,
        );
    }

    /** @return array<string,mixed> */
    public function plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! function_exists( 'get_plugin_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $updates        = get_plugin_updates();

        $total    = count( $all_plugins );
        $active   = count( $active_plugins );
        $inactive = $total - $active;
        $needs_update = count( $updates );

        // Must-use plugins.
        $mu_plugins = get_mu_plugins();

        return array(
            'total'        => $total,
            'active'       => $active,
            'inactive'     => $inactive,
            'needs_update' => $needs_update,
            'mu_plugins'   => count( $mu_plugins ),
        );
    }

    /** @return array<string,mixed> */
    public function php_info(): array {
        $opcache_enabled = function_exists( 'opcache_get_status' )
            ? ( opcache_get_status( false )['opcache_enabled'] ?? false )
            : false;

        $opcache_hit_rate = 0.0;
        if ( $opcache_enabled && function_exists( 'opcache_get_status' ) ) {
            $status = opcache_get_status( false );
            $hits   = $status['opcache_statistics']['hits']        ?? 0;
            $misses = $status['opcache_statistics']['misses']      ?? 0;
            $total  = $hits + $misses;
            $opcache_hit_rate = $total > 0 ? round( ( $hits / $total ) * 100, 2 ) : 0.0;
        }

        return array(
            'version'            => PHP_VERSION,
            'memory_limit_mb'    => (int) ini_get( 'memory_limit' ),
            'max_execution_time' => (int) ini_get( 'max_execution_time' ),
            'upload_max_mb'      => (int) ini_get( 'upload_max_filesize' ),
            'post_max_mb'        => (int) ini_get( 'post_max_size' ),
            'opcache_enabled'    => $opcache_enabled ? 1 : 0,
            'opcache_hit_rate'   => $opcache_hit_rate,
            'error_reporting'    => (int) ini_get( 'error_reporting' ),
        );
    }

    /** @return array<string,mixed> */
    public function server(): array {
        $disk_free  = disk_free_space( ABSPATH );
        $disk_total = disk_total_space( ABSPATH );
        $disk_used  = $disk_total - $disk_free;

        return array(
            'php_sapi'          => PHP_SAPI,
            'os'                => PHP_OS,
            'server_software'   => sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' ),
            'disk_total_gb'     => round( $disk_total / 1073741824, 2 ),
            'disk_free_gb'      => round( $disk_free  / 1073741824, 2 ),
            'disk_used_gb'      => round( $disk_used  / 1073741824, 2 ),
            'disk_used_pct'     => $disk_total > 0 ? round( ( $disk_used / $disk_total ) * 100, 1 ) : 0.0,
            'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG ? 1 : 0,
            'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 1 : 0,
        );
    }

    /** @return array<string,mixed> */
    public function cron(): array {
        $crons = _get_cron_array();
        if ( empty( $crons ) ) {
            return array(
                'total_events'   => 0,
                'overdue_events' => 0,
                'next_event_in'  => -1,
            );
        }

        $now          = time();
        $total        = 0;
        $overdue      = 0;
        $next_ts      = PHP_INT_MAX;

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $events ) {
                $count = count( $events );
                $total += $count;
                if ( $timestamp < $now ) {
                    $overdue += $count;
                }
                if ( $timestamp < $next_ts ) {
                    $next_ts = $timestamp;
                }
            }
        }

        $next_in = ( $next_ts < PHP_INT_MAX ) ? max( 0, $next_ts - $now ) : -1;

        return array(
            'total_events'   => $total,
            'overdue_events' => $overdue,
            'next_event_in'  => $next_in,
        );
    }

    /**
     * WooCommerce store metrics.
     * Delegates to WPZM_WooCommerce; returns an empty array if WC is not active.
     *
     * @return array<string,mixed>
     */
    public function woocommerce(): array {
        $wc = WPZM_WooCommerce::get_instance();
        if ( ! $wc->is_woocommerce_active() ) {
            return array();
        }
        $data = $wc->collect();
        // Strip internal error key if present.
        unset( $data['_error'] );
        return $data;
    }
}
