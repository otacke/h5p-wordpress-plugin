<?php
/**
 * H5P_Tables
 *
 * Registry of the H5P database tables and their scopes, table name resolution for the blog and
 * network levels, and existence checks.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Tables {

  /** Network database table prefix. */
  const NETWORK_DB_TABLE_PREFIX = 'h5p_network_';

  /** Scope of tables every blog has its own of: content, results, tags, events. */
  const SCOPE_BLOG = 'blog';

  /**
   * Scope of the library tables: every blog has its own in local mode, the network shares one set in network mode.
   * Migrations move them between the two levels.
   */
  const SCOPE_LIBRARY = 'library';

  /** Scope of tables that exist at network level only, in network mode, with no blog counterpart. */
  const SCOPE_NETWORK_ONLY = 'network_only';

  /** Scope of tables shared by the whole installation in either mode, without the network table prefix. */
  const SCOPE_GLOBAL = 'global';

  /**
   * Every H5P database table, by name without prefix, with its scope.
   *
   * The single source for resolving, creating, dropping and migrating tables. Order is the order tables are
   * dropped in.
   *
   * @var string[]
   */
  const TABLES = [
    'h5p_contents' => self::SCOPE_BLOG,
    'h5p_contents_libraries' => self::SCOPE_BLOG,
    'h5p_contents_user_data' => self::SCOPE_BLOG,
    'h5p_contents_tags' => self::SCOPE_BLOG,
    'h5p_tags' => self::SCOPE_BLOG,
    'h5p_results' => self::SCOPE_BLOG,
    'h5p_libraries' => self::SCOPE_LIBRARY,
    'h5p_libraries_libraries' => self::SCOPE_LIBRARY,
    'h5p_libraries_languages' => self::SCOPE_LIBRARY,
    'h5p_libraries_cachedassets' => self::SCOPE_LIBRARY,
    'h5p_counters' => self::SCOPE_BLOG,
    'h5p_events' => self::SCOPE_BLOG,
    'h5p_tmpfiles' => self::SCOPE_BLOG,
    'h5p_libraries_restrictions' => self::SCOPE_NETWORK_ONLY,
    'h5p_libraries_hub_cache' => self::SCOPE_GLOBAL,
  ];

  /**
   * Get names of the tables of the given scopes, in registry order.
   *
   * @param string ...$scopes One or more SCOPE_* constants.
   * @return string[] Table names without prefix.
   */
  public static function get_table_names(...$scopes) {
    return array_keys(array_filter(self::TABLES, function ($scope) use ($scopes) {
      return in_array($scope, $scopes, true);
    }));
  }

  /**
   * Get scope of a table, reporting unknown names, which are most likely typos.
   *
   * Unknown names are treated as blog tables, as before the registry existed.
   *
   * @param string $table_name Table name without prefix.
   * @return string SCOPE_* constant.
   */
  private static function get_scope($table_name) {
    if (isset(self::TABLES[$table_name])) {
      return self::TABLES[$table_name];
    }

    _doing_it_wrong(__METHOD__, sprintf('Unknown H5P database table "%s".', esc_html($table_name)), '1.18.0');

    return self::SCOPE_BLOG;
  }

  /**
   * Build full database table name, including correct prefix.
   *
   * @param string $table_name Table name without prefix (e.g. 'h5p_libraries').
   * @return string Full table name.
   */
  public static function build_full_db_table_name($table_name) {
    global $wpdb;

    switch (self::get_scope($table_name)) {
      case self::SCOPE_LIBRARY:
        return (H5P_Network_Mode::is_network_enabled())
          ? self::build_full_db_table_name_multisite($table_name)
          : self::build_full_db_table_name_singlesite($table_name);

      case self::SCOPE_NETWORK_ONLY:
        return self::build_full_db_table_name_multisite($table_name);

      case self::SCOPE_GLOBAL:
        return "{$wpdb->base_prefix}{$table_name}";

      default:
        // Not via build_full_db_table_name_singlesite(), which would report an unknown name a second time.
        return "{$wpdb->prefix}{$table_name}";
    }
  }

  /**
   * Return full table name using site local prefix.
   *
   * @param string $table_name Table name without prefix.
   * @return string Full table name.
   */
  public static function build_full_db_table_name_singlesite($table_name) {
    global $wpdb;

    self::get_scope($table_name); // Reports unknown names.

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

    $scope = self::get_scope($table_name);
    if ($scope === self::SCOPE_GLOBAL) {
      return "{$wpdb->base_prefix}{$table_name}";
    }
    if ($scope !== self::SCOPE_LIBRARY && $scope !== self::SCOPE_NETWORK_ONLY) {
      return "{$wpdb->prefix}{$table_name}"; // Blog table; get_scope() already reported an unknown name.
    }

    return $wpdb->base_prefix . self::NETWORK_DB_TABLE_PREFIX . $table_name;
  }

  /**
   * Determine whether given database table exists on current blog.
   *
   * Not memoized, because update_database() creates tables within a request.
   *
   * @since 1.18.0
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
   * @since 1.18.0
   * @param int[] $ids Values the placeholders stand in for.
   * @return string One %d per id, separated by commas, for interpolation in a query.
   */
  public static function int_placeholders(array $ids) {
    return implode(',', array_fill(0, count($ids), '%d'));
  }
}
