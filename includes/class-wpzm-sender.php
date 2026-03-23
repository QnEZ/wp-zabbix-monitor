<?php
/**
 * WPZM_Sender — pushes metric values to a Zabbix server/proxy using the
 * Zabbix Sender protocol (TCP, port 10051 by default).
 *
 * Protocol reference:
 *   https://www.zabbix.com/documentation/current/en/manual/appendix/protocols/zabbix_sender
 *
 * The protocol frame is:
 *   ZBXD\x01  (5 bytes header)
 *   <data_length> (8 bytes, little-endian int64)
 *   <json_payload>
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Sender {

    /** @var self|null */
    private static ?self $instance = null;

    /** Zabbix protocol header */
    const HEADER = "ZBXD\x01";

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Collect all enabled metrics and push them to the configured Zabbix server.
     *
     * @return array{success:bool, message:string, processed:int, failed:int}
     */
    public function push(): array {
        $settings = WPZM_Settings::get_instance();

        $server = $settings->get( 'zabbix_server', '' );
        $port   = (int) $settings->get( 'zabbix_port', 10051 );
        $host   = $settings->get( 'zabbix_host', '' );

        if ( empty( $server ) || empty( $host ) ) {
            return array(
                'success' => false,
                'message' => 'Zabbix server or host name is not configured.',
                'processed' => 0,
                'failed'    => 0,
            );
        }

        $enabled = $settings->get( 'enabled_metrics', array() );
        $data    = WPZM_Metrics::get_instance()->collect( $enabled );

        // Push the full metrics JSON blob to the Trapper master item.
        // Zabbix dependent items then extract individual values via JSONPath.
        // The key 'wordpress.metrics.push' must exist as a Trapper item on the host.
        $json_blob = wp_json_encode( $data );
        if ( false === $json_blob ) {
            return array(
                'success'   => false,
                'message'   => 'Failed to encode metrics as JSON.',
                'processed' => 0,
                'failed'    => 1,
            );
        }

        return $this->send( $server, $port, $host, array(
            'wordpress.metrics.push' => $json_blob,
        ) );
    }

    /**
     * Send an arbitrary key→value map to a Zabbix server.
     *
     * @param string                           $server  Zabbix server hostname or IP.
     * @param int                              $port    TCP port (default 10051).
     * @param string                           $host    Zabbix host name (as configured in Zabbix).
     * @param array<string,int|float|string>   $items   Key→value pairs.
     * @param int                              $timeout Socket timeout in seconds.
     * @return array{success:bool, message:string, processed:int, failed:int}
     */
    public function send(
        string $server,
        int    $port,
        string $host,
        array  $items,
        int    $timeout = 5
    ): array {
        if ( empty( $items ) ) {
            return array( 'success' => false, 'message' => 'No items to send.', 'processed' => 0, 'failed' => 0 );
        }

        $now  = time();
        $data = array();

        foreach ( $items as $key => $value ) {
            $data[] = array(
                'host'  => $host,
                'key'   => $key,
                'value' => (string) $value,
                'clock' => $now,
                'ns'    => 0,
            );
        }

        $payload = wp_json_encode( array(
            'request' => 'sender data',
            'data'    => $data,
            'clock'   => $now,
            'ns'      => 0,
        ) );

        if ( false === $payload ) {
            return array( 'success' => false, 'message' => 'JSON encoding failed.', 'processed' => 0, 'failed' => count( $items ) );
        }

        $frame = $this->build_frame( $payload );

        // ── Open TCP socket ───────────────────────────────────────────────────
        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen( $server, $port, $errno, $errstr, $timeout );

        if ( ! $socket ) {
            return array(
                'success' => false,
                'message' => "Connection failed: [{$errno}] {$errstr}",
                'processed' => 0,
                'failed'    => count( $items ),
            );
        }

        stream_set_timeout( $socket, $timeout );

        // ── Write ─────────────────────────────────────────────────────────────
        $written = fwrite( $socket, $frame );
        if ( false === $written ) {
            fclose( $socket );
            return array( 'success' => false, 'message' => 'Failed to write to socket.', 'processed' => 0, 'failed' => count( $items ) );
        }

        // ── Read response ─────────────────────────────────────────────────────
        $response = '';
        while ( ! feof( $socket ) ) {
            $chunk = fread( $socket, 2048 );
            if ( false === $chunk ) {
                break;
            }
            $response .= $chunk;
        }
        fclose( $socket );

        return $this->parse_response( $response, count( $items ) );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a Zabbix protocol frame.
     *
     * @param string $payload JSON string.
     * @return string Binary frame.
     */
    private function build_frame( string $payload ): string {
        $length = strlen( $payload );
        // Pack as little-endian 64-bit integer (two 32-bit ints).
        $packed = pack( 'VV', $length & 0xFFFFFFFF, ( $length >> 32 ) & 0xFFFFFFFF );
        return self::HEADER . $packed . $payload;
    }

    /**
     * Parse the Zabbix server response frame.
     *
     * @param string $response Raw binary response.
     * @param int    $sent     Number of items sent.
     * @return array{success:bool, message:string, processed:int, failed:int}
     */
    private function parse_response( string $response, int $sent ): array {
        // Strip the 13-byte header (ZBXD\x01 + 8-byte length).
        $header_len = strlen( self::HEADER ) + 8;
        $json       = substr( $response, $header_len );

        if ( empty( $json ) ) {
            return array(
                'success'   => false,
                'message'   => 'Empty response from Zabbix server.',
                'processed' => 0,
                'failed'    => $sent,
            );
        }

        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) ) {
            return array(
                'success'   => false,
                'message'   => 'Could not decode Zabbix response.',
                'processed' => 0,
                'failed'    => $sent,
            );
        }

        $info      = $decoded['info'] ?? '';
        $processed = 0;
        $failed    = 0;

        // Parse "Processed N Failed N Total N Seconds N.NNNNNN"
        if ( preg_match( '/processed:\s*(\d+)/i', $info, $m ) ) {
            $processed = (int) $m[1];
        }
        if ( preg_match( '/failed:\s*(\d+)/i', $info, $m ) ) {
            $failed = (int) $m[1];
        }

        $success = ( $decoded['response'] ?? '' ) === 'success';

        return array(
            'success'   => $success,
            'message'   => $info,
            'processed' => $processed,
            'failed'    => $failed,
        );
    }
}
