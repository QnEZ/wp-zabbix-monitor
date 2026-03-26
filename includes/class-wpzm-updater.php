<?php
/**
 * WPZM_Updater — handles plugin updates from GitHub releases.
 *
 * Integrates with WordPress's native update system to check for new releases
 * on GitHub and display update notifications in the admin panel.
 *
 * @package WP_Zabbix_Monitor
 */

defined( 'ABSPATH' ) || exit;

class WPZM_Updater {

	/** @var self|null */
	private static ?self $instance = null;

	/** GitHub repository owner/name */
	const GITHUB_REPO = 'QnEZ/wp-zabbix-monitor';

	/** GitHub API base URL */
	const GITHUB_API = 'https://api.github.com/repos/';

	/** Transient key for caching release info */
	const TRANSIENT_KEY = 'wpzm_update_check';

	/** Cache duration in seconds (12 hours) */
	const CACHE_DURATION = 43200;

	/** Singleton accessor */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Hook into WordPress update system.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_update' ), 10, 3 );
	}

	// ─── Update checking ──────────────────────────────────────────────────────

	/**
	 * Check for plugin updates from GitHub.
	 * Called by WordPress when checking for plugin updates.
	 *
	 * @param object|null $transient
	 * @return object
	 */
	public function check_for_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		// Get the latest release from GitHub.
		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $transient;
		}

		// Compare versions.
		$latest_version = $this->parse_version( $release['tag_name'] );
		$current_version = WPZM_VERSION;

		if ( version_compare( $latest_version, $current_version, '>' ) ) {
			// New version available.
			$plugin_file = plugin_basename( WPZM_PLUGIN_FILE );

			$update_obj = new \stdClass();
			$update_obj->slug = 'wp-zabbix-monitor';
			$update_obj->plugin = $plugin_file;
			$update_obj->new_version = $latest_version;
			$update_obj->url = $release['html_url'];
			$update_obj->package = $this->get_download_url( $release );
			$update_obj->tested = '6.9';
			$update_obj->requires_php = '7.4';
			$update_obj->requires = '5.9';

			if ( ! isset( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $plugin_file ] = $update_obj;
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the update modal.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'wp-zabbix-monitor' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		$info = new \stdClass();
		$info->name = 'WP Zabbix Monitor';
		$info->slug = 'wp-zabbix-monitor';
		$info->version = $this->parse_version( $release['tag_name'] );
		$info->author = 'QnEZ Servers';
		$info->author_profile = 'https://github.com/QnEZ';
		$info->requires = '5.9';
		$info->requires_php = '7.4';
		$info->tested = '6.9';
		$info->homepage = 'https://github.com/QnEZ/wp-zabbix-monitor';
		$info->download_link = $this->get_download_url( $release );
		$info->sections = array(
			'description' => $release['body'] ?? 'Full-stack WordPress monitoring plugin for Zabbix.',
			'changelog'   => $this->format_changelog( $release['body'] ?? '' ),
		);

		return $info;
	}

	/**
	 * Cleanup after plugin update.
	 *
	 * @param bool   $response
	 * @param string $hook_extra
	 * @param array  $result
	 * @return bool
	 */
	public function after_update( $response, $hook_extra, $result ) {
		// Clear the update cache after successful update.
		delete_transient( self::TRANSIENT_KEY );
		return $response;
	}

	// ─── GitHub API helpers ───────────────────────────────────────────────────

	/**
	 * Get the latest release from GitHub.
	 *
	 * @return array|null
	 */
	private function get_latest_release(): ?array {
		// Check cache first.
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = self::GITHUB_API . self::GITHUB_REPO . '/releases/latest';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-Zabbix-Monitor/' . WPZM_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$release = json_decode( $body, true );

		if ( ! is_array( $release ) || isset( $release['message'] ) ) {
			return null;
		}

		// Cache the result.
		set_transient( self::TRANSIENT_KEY, $release, self::CACHE_DURATION );

		return $release;
	}

	/**
	 * Get the download URL for a release (ZIP file).
	 *
	 * @param array $release
	 * @return string
	 */
	private function get_download_url( array $release ): string {
		// Look for the plugin ZIP in assets.
		if ( isset( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( strpos( $asset['name'], 'wp-zabbix-monitor' ) !== false && strpos( $asset['name'], '.zip' ) !== false ) {
					return $asset['browser_download_url'];
				}
			}
		}

		// Fallback to GitHub archive URL.
		return self::GITHUB_API . self::GITHUB_REPO . '/zipball/' . $release['tag_name'];
	}

	/**
	 * Parse version from tag name (e.g., "v1.3.2" -> "1.3.2").
	 *
	 * @param string $tag
	 * @return string
	 */
	private function parse_version( string $tag ): string {
		return ltrim( $tag, 'v' );
	}

	/**
	 * Format changelog from release notes.
	 *
	 * @param string $body
	 * @return string
	 */
	private function format_changelog( string $body ): string {
		if ( empty( $body ) ) {
			return 'See GitHub releases for details.';
		}

		// Convert markdown to HTML (basic).
		$html = wpautop( $body );
		$html = str_replace( '**', '<strong>', $html );
		$html = str_replace( '__', '<strong>', $html );

		return $html;
	}
}
