<?php
/**
 * H5P_Tables
 *
 * Database table names and existence checks, resolving the blog level from the network level
 * in network mode.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
class H5P_Tables {

  /** Network database table prefix. */
  const NETWORK_DB_TABLE_PREFIX = 'h5p_network_';

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
   * Database tables that exist at network level only, without a blog level counterpart to migrate from or to.
   *
   * @var string[]
   */
  const NETWORK_ONLY_DATABASE_TABLE_NAMES = [
    H5P_Library_Restrictions::TABLE_NAME
  ];

  /**
   * Build full database table name, including correct prefix.
   *
   * @param string $table_name Table name without prefix (e.g. 'h5p_libraries').
   * @return string Full table name.
   */
  public static function build_full_db_table_name($table_name) {
    return (H5P_Network_Mode::is_network_enabled())
      ? H5P_Tables::build_full_db_table_name_multisite($table_name)
      : H5P_Tables::build_full_db_table_name_singlesite($table_name);
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

    $is_table_network_table = in_array($table_name, self::NETWORK_DATABASE_TABLE_NAMES, true)
      || in_array($table_name, self::NETWORK_ONLY_DATABASE_TABLE_NAMES, true);
    if (!$is_table_network_table) {
      return H5P_Tables::build_full_db_table_name_singlesite($table_name);
    }

    return $wpdb->base_prefix . self::NETWORK_DB_TABLE_PREFIX . $table_name;
  }

  /**
   * Determine whether given database table exists on current blog.
   *
   * Not memoized, because update_database() creates tables within a request.
   *
   * @since 1.19.0
   * @param string $table Full table name.
   * @return bool
   */
  public static function table_exists($table) {
    global $wpdb;

    return $wpdb->get_var(
      $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    ) === $table;
  }

  /**
   * Build a comma separated list of %d placeholders for $wpdb->prepare().
   *
   * @since 1.19.0
   * @param int[] $ids Values the placeholders stand in for.
   * @return string One %d per id, separated by commas, for interpolation in a query.
   */
  public static function int_placeholders(array $ids) {
    return implode(',', array_fill(0, count($ids), '%d'));
  }
}
