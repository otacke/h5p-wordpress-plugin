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
}
