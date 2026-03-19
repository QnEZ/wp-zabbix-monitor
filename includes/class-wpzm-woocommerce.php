<?php
/**
 * WPZM_WooCommerce — collects WooCommerce store metrics for Zabbix monitoring.
 *
 * All queries are designed to be lightweight and read-only. Heavy aggregations
 * (revenue, orders) use a 5-minute transient cache so they do not add latency
 * to every WP-Cron push cycle.
 *
 * Metrics collected
 * ─────────────────
 * Orders
 *   woocommerce.orders.total_today        — orders placed in the last 24 h
 *   woocommerce.orders.total_week         — orders placed in the last 7 days
 *   woocommerce.orders.pending            — orders with status "pending"
 *   woocommerce.orders.processing         — orders with status "processing"
 *   woocommerce.orders.on_hold            — orders with status "on-hold"
 *   woocommerce.orders.failed             — orders with status "failed"
 *   woocommerce.orders.refunded           — orders with status "refunded"
 *   woocommerce.orders.cancelled          — orders with status "cancelled"
 *   woocommerce.orders.per_hour           — rolling 1-hour order rate
 *
 * Revenue
 *   woocommerce.revenue.today             — gross revenue today (store currency)
 *   woocommerce.revenue.week              — gross revenue last 7 days
 *   woocommerce.revenue.avg_order_value   — average order value (last 30 days)
 *
 * Cart / Checkout
 *   woocommerce.cart.abandoned_sessions   — sessions with items but no order (last 24 h)
 *
 * Products
 *   woocommerce.products.total            — total published products
 *   woocommerce.products.out_of_stock     — products with stock_status = outofstock
 *   woocommerce.products.low_stock        — products below low-stock threshold
 *   woocommerce.products.backorders       — products allowing backorders
 *
 * Customers
 *   woocommerce.customers.total           — total customer accounts
 *   woocommerce.customers.new_today       — new customers registered today
 *
 * Coupons
 *   woocommerce.coupons.total             — total active coupons
 *
 * Reviews
 *   woocommerce.reviews.pending           — product reviews awaiting moderation
 *
 * @package WP_Zabbix_Monitor
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class WPZM_WooCommerce {

    /** @var self|null */
    private static ?self $instance = null;

    /** Transient key for cached aggregates */
    const CACHE_KEY = 'wpzm_wc_metrics_cache';

    /** Cache TTL in seconds */
    const CACHE_TTL = 300; // 5 minutes

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Collect all WooCommerce metrics.
     *
     * Returns an empty array (with an error key) if WooCommerce is not active,
     * so callers can detect the absence gracefully.
     *
     * @return array<string, mixed>
     */
    public function collect(): array {
        if ( ! $this->is_woocommerce_active() ) {
            return array( '_error' => 'WooCommerce is not active.' );
        }

        // Try cache first for the expensive aggregates.
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $metrics = array_merge(
            $this->order_metrics(),
            $this->revenue_metrics(),
            $this->cart_metrics(),
            $this->product_metrics(),
            $this->customer_metrics(),
            $this->coupon_metrics(),
            $this->review_metrics()
        );

        set_transient( self::CACHE_KEY, $metrics, self::CACHE_TTL );

        return $metrics;
    }

    /**
     * Flush the metrics cache (call after a push to ensure fresh data next cycle).
     */
    public function flush_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // ─── Metric groups ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function order_metrics(): array {
        global $wpdb;

        $now        = current_time( 'mysql' );
        $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
        $week_start  = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', current_time( 'timestamp' ) ) );
        $hour_start  = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour', current_time( 'timestamp' ) ) );

        // Use HPOS (High-Performance Order Storage) if available, otherwise fall back to posts table.
        if ( $this->is_hpos_enabled() ) {
            $orders_table = $wpdb->prefix . 'wc_orders';

            $status_counts = $wpdb->get_results(
                "SELECT status, COUNT(*) AS cnt FROM {$orders_table} WHERE type = 'shop_order' GROUP BY status",
                ARRAY_A
            );
            $status_map = array();
            foreach ( (array) $status_counts as $row ) {
                $status_map[ $row['status'] ] = (int) $row['cnt'];
            }

            $orders_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_order' AND date_created_gmt >= %s",
                get_gmt_from_date( $today_start )
            ) );

            $orders_week = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_order' AND date_created_gmt >= %s",
                get_gmt_from_date( $week_start )
            ) );

            $orders_hour = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_order' AND date_created_gmt >= %s",
                get_gmt_from_date( $hour_start )
            ) );
        } else {
            // Classic posts-based storage.
            $status_counts = $wpdb->get_results(
                "SELECT post_status, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'shop_order' GROUP BY post_status",
                ARRAY_A
            );
            $status_map = array();
            foreach ( (array) $status_counts as $row ) {
                // Strip the wc- prefix for consistency.
                $key = str_replace( 'wc-', '', $row['post_status'] );
                $status_map[ $key ] = (int) $row['cnt'];
            }

            $orders_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_date >= %s",
                $today_start
            ) );

            $orders_week = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_date >= %s",
                $week_start
            ) );

            $orders_hour = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_date >= %s",
                $hour_start
            ) );
        }

        return array(
            'orders_total_today'  => $orders_today,
            'orders_total_week'   => $orders_week,
            'orders_per_hour'     => $orders_hour,
            'orders_pending'      => $status_map['pending']    ?? 0,
            'orders_processing'   => $status_map['processing'] ?? 0,
            'orders_on_hold'      => $status_map['on-hold']    ?? $status_map['on_hold'] ?? 0,
            'orders_failed'       => $status_map['failed']     ?? 0,
            'orders_refunded'     => $status_map['refunded']   ?? 0,
            'orders_cancelled'    => $status_map['cancelled']  ?? 0,
        );
    }

    /** @return array<string, mixed> */
    private function revenue_metrics(): array {
        global $wpdb;

        $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
        $week_start  = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', current_time( 'timestamp' ) ) );
        $month_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', current_time( 'timestamp' ) ) );

        if ( $this->is_hpos_enabled() ) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $completed_statuses = array( 'wc-completed', 'wc-processing' );
            $placeholders = implode( ',', array_fill( 0, count( $completed_statuses ), '%s' ) );

            $revenue_today = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM {$orders_table}
                 WHERE type = 'shop_order' AND status IN ({$placeholders}) AND date_created_gmt >= %s",
                ...array_merge( $completed_statuses, array( get_gmt_from_date( $today_start ) ) )
            ) );

            $revenue_week = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM {$orders_table}
                 WHERE type = 'shop_order' AND status IN ({$placeholders}) AND date_created_gmt >= %s",
                ...array_merge( $completed_statuses, array( get_gmt_from_date( $week_start ) ) )
            ) );

            $avg_order = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(AVG(total_amount), 0) FROM {$orders_table}
                 WHERE type = 'shop_order' AND status IN ({$placeholders}) AND date_created_gmt >= %s",
                ...array_merge( $completed_statuses, array( get_gmt_from_date( $month_start ) ) )
            ) );
        } else {
            $revenue_today = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s
                   AND pm.meta_key = '_order_total'",
                $today_start
            ) );

            $revenue_week = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s
                   AND pm.meta_key = '_order_total'",
                $week_start
            ) );

            $avg_order = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(AVG(meta_value), 0) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing')
                   AND p.post_date >= %s
                   AND pm.meta_key = '_order_total'",
                $month_start
            ) );
        }

        return array(
            'revenue_today'         => round( $revenue_today, 2 ),
            'revenue_week'          => round( $revenue_week, 2 ),
            'revenue_avg_order'     => round( $avg_order, 2 ),
        );
    }

    /** @return array<string, mixed> */
    private function cart_metrics(): array {
        global $wpdb;

        // Count WooCommerce sessions that have cart contents but no associated order
        // in the last 24 hours. Uses the wc_sessions table (available in all WC versions).
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        $table_exists   = $wpdb->get_var( "SHOW TABLES LIKE '{$sessions_table}'" );

        if ( ! $table_exists ) {
            return array( 'cart_abandoned_sessions' => 0 );
        }

        $cutoff = time() - DAY_IN_SECONDS;

        // Sessions that have a non-empty cart value and were active in the last 24h.
        $abandoned = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table}
             WHERE session_expiry > %d
               AND session_value LIKE %s",
            $cutoff,
            '%cart%'
        ) );

        return array( 'cart_abandoned_sessions' => $abandoned );
    }

    /** @return array<string, mixed> */
    private function product_metrics(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        $out_of_stock = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_stock_status' AND meta_value = 'outofstock'"
        );

        // Low stock threshold from WC settings (default: 2).
        $low_stock_threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

        $low_stock = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id
             WHERE pm.meta_key  = '_manage_stock' AND pm.meta_value  = 'yes'
               AND pm2.meta_key = '_stock'        AND CAST(pm2.meta_value AS SIGNED) <= %d
               AND CAST(pm2.meta_value AS SIGNED) > 0",
            $low_stock_threshold
        ) );

        $backorders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_backorders' AND meta_value IN ('yes','notify')"
        );

        return array(
            'products_total'       => $total,
            'products_out_of_stock' => $out_of_stock,
            'products_low_stock'   => $low_stock,
            'products_backorders'  => $backorders,
        );
    }

    /** @return array<string, mixed> */
    private function customer_metrics(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
             WHERE um.meta_key = '{$wpdb->prefix}capabilities'
               AND um.meta_value LIKE '%customer%'"
        );

        $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

        $new_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
             WHERE um.meta_key = '{$wpdb->prefix}capabilities'
               AND um.meta_value LIKE '%customer%'
               AND u.user_registered >= %s",
            $today_start
        ) );

        return array(
            'customers_total'     => $total,
            'customers_new_today' => $new_today,
        );
    }

    /** @return array<string, mixed> */
    private function coupon_metrics(): array {
        global $wpdb;

        // WooCommerce 8.x+ uses the `wc_coupons` table; older versions use posts.
        $coupons_table = $wpdb->prefix . 'wc_coupons';
        $table_exists  = $wpdb->get_var( "SHOW TABLES LIKE '{$coupons_table}'" );

        if ( $table_exists ) {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$coupons_table}" );
        } else {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'shop_coupon' AND post_status = 'publish'"
            );
        }

        return array( 'coupons_total' => $total );
    }

    /** @return array<string, mixed> */
    private function review_metrics(): array {
        global $wpdb;

        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
             WHERE p.post_type = 'product'
               AND c.comment_approved = '0'"
        );

        return array( 'reviews_pending' => $pending );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Check whether WooCommerce is active.
     */
    public function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Detect whether WooCommerce High-Performance Order Storage (HPOS) is enabled.
     * Available from WooCommerce 7.1+.
     */
    private function is_hpos_enabled(): bool {
        if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return false;
        }
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
