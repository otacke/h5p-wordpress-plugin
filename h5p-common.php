<?php
/**
 * H5PCommons
 *
 * Shared constants and helper functions for the H5P plugin.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
class H5PCommons {

	/** HTTP 403 Forbidden response code. */
	const HTTP_FORBIDDEN = 403;

	/** HTTP 200 OK response code. */
	const HTTP_OK = 200;

	/** Network database table prefix. */
	const NETWORK_DB_TABLE_PREFIX = 'h5p_network_';

	/**
	 * Name of the network level H5P files directory, relative to the uploads root.
	 */
	const NETWORK_DIRECTORY_NAME = 'h5p_network';

	/**
	 * Database tables that are managed at network level in multisite installation.
	 *
	 * @var string[]
	 */
	const NETWORK_DATABASE_TABLE_NAMES = [
		'h5p_libraries',
		'h5p_libraries_cachedassets',
		'h5p_libraries_languages',
		'h5p_libraries_libraries'
	];

	/**
	 * Cached network enable flag to avoid repeated database calls.
	 *
	 * @var bool|null
	 */
	private static $is_network_enabled = null;

	public static function is_network_enabled() {
		if (null === self::$is_network_enabled) {
			self::$is_network_enabled = get_site_option('h5p_network_enabled', false);
		}

		return self::$is_network_enabled;
	}

	/**
	 * Turn network mode on or off.
	 *
	 * Keeps the cached flag in step with the stored option, so callers that go
	 * on to act on the new state within the same request see it.
	 *
	 * @param bool $enabled Whether network mode should be enabled.
	 */
	public static function set_network_enabled($enabled) {
		$enabled = (bool) $enabled;

		update_site_option('h5p_network_enabled', $enabled);
		self::$is_network_enabled = $enabled;
	}

	/**
	 * Whether the current user may manage libraries.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_libraries() {
		return self::current_user_can_library_capability('manage_h5p_libraries');
	}

	/**
	 * Whether the current user may install recommended libraries.
	 *
	 * @return bool
	 */
	public static function current_user_can_install_recommended_libraries() {
		return self::current_user_can_library_capability('install_recommended_h5p_libraries');
	}

	/**
	 * Whether the current user holds a capability that writes to libraries.
	 *
	 * In network mode the libraries folder is shared by every blog, so writing to
	 * it is for network admins only. H5P_Plugin::assign_capabilities() already
	 * revokes these capabilities from blog roles when network mode is on, but
	 * roles are stored per blog and a capability can also be granted straight to
	 * a user, so the state of the request is checked here as well.
	 *
	 * @param string $capability Capability to check.
	 * @return bool
	 */
	private static function current_user_can_library_capability($capability) {
		if (self::is_network_enabled() && !current_user_can('manage_network')) {
			return false;
		}

		return current_user_can($capability);
	}

	/**
	 * Get the path to the network level H5P files folder.
	 *
	 * Unlike wp_upload_dir(), this always resolves to the network wide uploads
	 * root, so it returns the same directory no matter which blog is current.
	 *
	 * @return string
	 */
	public static function get_h5p_network_path() {
		$upload_dir = wp_upload_dir();
		$base = self::strip_blog_from_uploads_base($upload_dir['basedir']);

		return $base . '/' . self::NETWORK_DIRECTORY_NAME;
	}

	/**
	 * Get the URL for the network level H5P files folder.
	 *
	 * Always absolute, because H5P core and the editor only leave asset paths
	 * untouched when they contain a scheme. Mirrors the HTTPS fixup done by
	 * H5P_Plugin::get_h5p_url() so assets are not served over plain HTTP on an
	 * SSL page.
	 *
	 * @return string
	 */
	public static function get_h5p_network_url() {
		$upload_dir = wp_upload_dir();
		$base = self::strip_blog_from_uploads_base($upload_dir['baseurl']);
		$url = $base . '/' . self::NETWORK_DIRECTORY_NAME;

		if (is_ssl() && substr($url, 0, 5) !== 'https') {
			$url = 'https' . substr($url, 4);
		}

		return $url;
	}

	/**
	 * Strip the per blog segment from an uploads base path or URL.
	 *
	 * On a subsite wp_upload_dir() points inside "sites/<blog id>". The network
	 * level directory lives next to that, in the uploads root.
	 *
	 * @param string $base Uploads base path or URL.
	 * @return string Base without the trailing per blog segment.
	 */
	private static function strip_blog_from_uploads_base($base) {
		return preg_replace('#/sites/\d+$#', '', $base);
	}

	/**
	 * Build full database table name, including correct prefix.
	 *
	 * @param string $table_name Table name without prefix (e.g. 'h5p_libraries').
	 * @return string Full table name.
	 */
	public static function build_full_db_table_name($table_name) {
		return (self::is_network_enabled())
			? H5PCommons::build_full_db_table_name_multisite($table_name)
			: H5PCommons::build_full_db_table_name_singlesite($table_name);
	}

	/**
	 * Return full table name using site-local prefix.
	 *
	 * @param string $table_name Table name without prefix.
	 * @return string Full table name.
	 */
	public static function build_full_db_table_name_singlesite($table_name) {
		global $wpdb;

		return "{$wpdb->prefix}{$table_name}";
	}

	/**
	 * Return full table name for multisite installation.
	 *
	 * @param string $table_name Table name without prefix.
	 * @return string Full table name.
	 */
	public static function build_full_db_table_name_multisite($table_name) {
		global $wpdb;

		$is_table_network_table = in_array($table_name, self::NETWORK_DATABASE_TABLE_NAMES, true);
		if (!$is_table_network_table) {
			return H5PCommons::build_full_db_table_name_singlesite($table_name);
		}

		return $wpdb->base_prefix . self::NETWORK_DB_TABLE_PREFIX . $table_name;
	}

	/**
	 * Run callback once per blog.
	 *
	 * @since 1.19.0
	 * @param callable $callback Receives the current blog id.
	 */
	public static function for_each_blog(callable $callback) {
		if (!is_multisite()) {
			$callback(get_current_blog_id());
			return;
		}

		foreach (get_sites(array('fields' => 'ids')) as $blog_id) {
			switch_to_blog($blog_id);

			try {
				$callback((int) $blog_id);
			}
			finally {
				restore_current_blog();
			}
		}
	}
}
