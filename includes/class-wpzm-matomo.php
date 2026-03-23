<?php
/**
 * WPZM_Matomo — collects analytics metrics from a self-hosted Matomo instance.
 *
 * Metrics collected:
 *   - Site-level: pageviews, unique visitors, bounce rate, avg session duration
 *   - Traffic sources: direct, organic, referral, social, campaigns
 *   - Top pages: most-visited articles with visit counts
 *
 * Requires Matomo API token and site ID. Queries the Matomo REST API.
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Matomo {

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
     * Collect Matomo analytics metrics for the past 30 days.
     *
     * @return array<string,mixed>
     */
    public function collect(): array {
        $settings = WPZM_Settings::get_instance();
        $matomo_url = $settings->get( 'matomo_url', '' );
        $matomo_token = $settings->get( 'matomo_token', '' );
        $matomo_site_id = (int) $settings->get( 'matomo_site_id', 0 );

        if ( empty( $matomo_url ) || empty( $matomo_token ) || empty( $matomo_site_id ) ) {
            return array(
                'error' => 'Matomo not configured. Set URL, token, and site ID in settings.',
            );
        }

        try {
            $site_metrics = $this->get_site_metrics( $matomo_url, $matomo_token, $matomo_site_id );
            $sources = $this->get_traffic_sources( $matomo_url, $matomo_token, $matomo_site_id );
            $top_pages = $this->get_top_pages( $matomo_url, $matomo_token, $matomo_site_id );

            return array(
                'site'       => $site_metrics,
                'sources'    => $sources,
                'top_pages'  => $top_pages,
            );
        } catch ( \Throwable $e ) {
            return array(
                'error' => $e->getMessage(),
            );
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Get site-level metrics: pageviews, visitors, bounce rate, avg session.
     *
     * @param string $matomo_url
     * @param string $matomo_token
     * @param int    $matomo_site_id
     * @return array<string,int|float>
     */
    private function get_site_metrics( string $matomo_url, string $matomo_token, int $matomo_site_id ): array {
        $url = add_query_arg(
            array(
                'module'    => 'API',
                'method'    => 'VisitsSummary.get',
                'idSite'    => $matomo_site_id,
                'period'    => 'day',
                'date'      => 'today',
                'format'    => 'JSON',
                'token_auth' => $matomo_token,
            ),
            rtrim( $matomo_url, '/' ) . '/'
        );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'Matomo API error: ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || isset( $data['result'] ) && 'error' === $data['result'] ) {
            throw new \Exception( 'Matomo API returned error: ' . ( $data['message'] ?? 'Unknown error' ) );
        }

        // Extract metrics from today's data (key is 'today' or YYYY-MM-DD format)
        $today_key = array_key_first( $data );
        $today_data = $data[ $today_key ] ?? array();

        return array(
            'pageviews'           => (int) ( $today_data['nb_pageviews'] ?? 0 ),
            'unique_visitors'     => (int) ( $today_data['nb_uniq_visitors'] ?? 0 ),
            'bounce_rate_pct'     => (float) ( $today_data['bounce_rate'] ?? 0 ),
            'avg_session_duration_sec' => (int) ( $today_data['avg_time_on_site'] ?? 0 ),
            'visits'              => (int) ( $today_data['nb_visits'] ?? 0 ),
        );
    }

    /**
     * Get traffic by source (direct, organic, referral, social, campaigns).
     *
     * @param string $matomo_url
     * @param string $matomo_token
     * @param int    $matomo_site_id
     * @return array<string,int>
     */
    private function get_traffic_sources( string $matomo_url, string $matomo_token, int $matomo_site_id ): array {
        $url = add_query_arg(
            array(
                'module'    => 'API',
                'method'    => 'Referrers.getReferrerType',
                'idSite'    => $matomo_site_id,
                'period'    => 'day',
                'date'      => 'today',
                'format'    => 'JSON',
                'token_auth' => $matomo_token,
            ),
            rtrim( $matomo_url, '/' ) . '/'
        );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'Matomo traffic sources API error: ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        // Map Matomo referrer types to readable labels
        $sources = array(
            'direct'    => 0,
            'organic'   => 0,
            'referral'  => 0,
            'social'    => 0,
            'campaigns' => 0,
        );

        foreach ( $data as $type => $count ) {
            $count = (int) $count;
            switch ( $type ) {
                case 'direct':
                    $sources['direct'] = $count;
                    break;
                case 'search':
                    $sources['organic'] = $count;
                    break;
                case 'referral':
                    $sources['referral'] = $count;
                    break;
                case 'social':
                    $sources['social'] = $count;
                    break;
                case 'campaign':
                    $sources['campaigns'] = $count;
                    break;
            }
        }

        return $sources;
    }

    /**
     * Get top 5 most-visited pages with visit counts.
     *
     * @param string $matomo_url
     * @param string $matomo_token
     * @param int    $matomo_site_id
     * @return array<string,array<string,mixed>>
     */
    private function get_top_pages( string $matomo_url, string $matomo_token, int $matomo_site_id ): array {
        $url = add_query_arg(
            array(
                'module'    => 'API',
                'method'    => 'Actions.getPageUrls',
                'idSite'    => $matomo_site_id,
                'period'    => 'day',
                'date'      => 'today',
                'format'    => 'JSON',
                'filter_limit' => 5,
                'token_auth' => $matomo_token,
            ),
            rtrim( $matomo_url, '/' ) . '/'
        );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'Matomo top pages API error: ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $top_pages = array();
        $index = 1;

        foreach ( array_slice( $data, 0, 5 ) as $page ) {
            $top_pages[ "page_{$index}" ] = array(
                'url'   => $page['label'] ?? '',
                'hits'  => (int) ( $page['nb_hits'] ?? 0 ),
            );
            $index++;
        }

        // Pad with empty entries if fewer than 5 pages
        while ( $index <= 5 ) {
            $top_pages[ "page_{$index}" ] = array(
                'url'  => '',
                'hits' => 0,
            );
            $index++;
        }

        return $top_pages;
    }
}
