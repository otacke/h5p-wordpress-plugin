<?php
/**
 * H5PCommons
 *
 * Shared constants and helper functions for H5P plugin.
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

	/** Name of network level H5P files directory, relative to uploads root. */
	const NETWORK_DIRECTORY_NAME = 'h5p_network';

	/**
	 * Database tables managed at network level in multisite installation.
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
	 * Turn network mode on or off, keeping cached flag in step so same request sees new state.
	 *
	 *
	 * @param bool $enabled Whether network mode should be enabled.
	 */
	public static function set_network_enabled($enabled) {
		$enabled = (bool) $enabled;

		update_site_option('h5p_network_enabled', $enabled);
		self::$is_network_enabled = $enabled;
	}

	/**
	 * Whether current user may manage libraries.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_libraries() {
		return self::current_user_can_library_capability('manage_h5p_libraries');
	}

	/**
	 * Whether current user may install recommended libraries.
	 *
	 * @return bool
	 */
	public static function current_user_can_install_recommended_libraries() {
		return self::current_user_can_library_capability('install_recommended_h5p_libraries');
	}

	/**
	 * Whether current user holds capability that writes to libraries.
	 *
	 * In network mode, libraries folder is shared by every blog, so only network admins may write.
	 * assign_capabilities() revokes this from blog roles, but roles are per blog and capabilities can
	 * be granted straight to users, so request state is checked here too.
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
	 * Get path to network level H5P files folder.
	 *
	 * Unlike wp_upload_dir(), always resolves to network wide uploads root, whichever blog is current.
	 *
	 * @return string
	 */
	public static function get_h5p_network_path() {
		$upload_dir = wp_upload_dir();
		$base = self::strip_blog_from_uploads_base($upload_dir['basedir']);

		return $base . '/' . self::NETWORK_DIRECTORY_NAME;
	}

	/**
	 * Get URL for network level H5P files folder.
	 *
	 * Always absolute, since H5P core and editor only leave asset paths untouched when they carry
	 * scheme. Mirrors HTTPS fixup of H5P_Plugin::get_h5p_url(), so SSL pages never load plain HTTP.
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
	 * Strip per blog segment from uploads base path or URL.
	 *
	 * On subsites wp_upload_dir() points inside "sites/<blog id>"; network directory sits beside it.
	 *
	 * @param string $base Uploads base path or URL.
	 * @return string Base without trailing per blog segment.
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
	 * Return full table name using site local prefix.
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
	 * Get ids of all blogs in network.
	 *
	 * get_sites() defaults to 'number' => 100, so it must be 0 to get every blog. Without that,
	 * networks above 100 blogs are silently truncated and network wide operations skip rest.
	 *
	 * @since 1.19.0
	 * @return int[] Blog ids, ordered by id.
	 */
	public static function get_all_blog_ids() {
		return get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 0,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Get number of blogs in network.
	 *
	 * @since 1.19.0
	 * @return int Number of blogs.
	 */
	public static function count_blogs() {
		if (!is_multisite()) {
			return 1;
		}

		return count(self::get_all_blog_ids());
	}

	/**
	 * Get ids of one page of blogs in network.
	 *
	 * Ordered by id, so paging by offset stays stable unless blogs are created or deleted meanwhile.
	 *
	 * @since 1.19.0
	 * @param int $offset Number of blogs to skip.
	 * @param int $number Maximum number of blogs to return.
	 * @return int[] Blog ids, ordered by id.
	 */
	public static function get_blog_ids_page($offset, $number) {
		return get_sites(
			array(
				'fields'  => 'ids',
				'number'  => (int) $number,
				'offset'  => (int) $offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Run callback once per blog.
	 *
	 * @since 1.19.0
	 * @param callable $callback Receives current blog id.
	 */
	public static function for_each_blog(callable $callback) {
		if (!is_multisite()) {
			$callback(get_current_blog_id());
			return;
		}

		self::switch_through_blogs(self::get_all_blog_ids(), $callback);
	}

	/**
	 * Run callback once per blog of one page of blogs.
	 *
	 * Lets long running operations work through network in several requests. Callback may stop early,
	 * so it receives position of blog within whole network as second argument.
	 *
	 * @since 1.19.0
	 * @param int      $offset   Number of blogs to skip.
	 * @param int      $number   Maximum number of blogs to visit.
	 * @param callable $callback Receives current blog id and its offset. Returning false stops.
	 * @return int Offset after last visited blog.
	 */
	public static function for_each_blog_page($offset, $number, callable $callback) {
		$offset = (int) $offset;

		if (!is_multisite()) {
			if ($offset === 0 && $number > 0) {
				$callback(get_current_blog_id(), $offset);
				return 1;
			}

			return $offset;
		}

		return self::switch_through_blogs(
			self::get_blog_ids_page($offset, $number),
			$callback,
			$offset
		);
	}

	/**
	 * Run callback for each given blog, in blog context.
	 *
	 * @since 1.19.0
	 * @param int[]    $blog_ids Blog ids to visit.
	 * @param callable $callback Receives current blog id and its offset. Returning false stops.
	 * @param int      $offset   Offset of first blog in list.
	 * @return int Offset after last visited blog.
	 */
	private static function switch_through_blogs($blog_ids, callable $callback, $offset = 0) {
		foreach ($blog_ids as $blog_id) {
			switch_to_blog($blog_id);

			try {
				$result = $callback((int) $blog_id, $offset);
			}
			finally {
				restore_current_blog();
			}

			$offset++;

			if ($result === false) {
				break;
			}
		}

		return $offset;
	}
}
