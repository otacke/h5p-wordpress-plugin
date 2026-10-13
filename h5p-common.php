<?php
/**
 * H5PCommons
 *
 * Shared constants and helper functions for H5P plugin.
 *
 * Facade: every method is a one-line delegate to the class in common/ that owns it, and the
 * non-HTTP constants are aliases of the owning class's constants. Call sites keep using this
 * class unchanged.
 *
 * Scope: the facade covers the four shared helpers (H5P_Network_Mode, H5P_Tables,
 * H5P_Network_Paths, H5P_Blogs) and nothing else. Plugin code outside common/ calls them through
 * the facade; classes inside common/ call each other directly. Domain classes, e.g.
 * H5P_Library_Restrictions, are called directly and get no facade entries.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5PCommons {

  /** HTTP 403 Forbidden response code. */
  const HTTP_FORBIDDEN = 403;

  /** HTTP 200 OK response code. */
  const HTTP_OK = 200;

  /** Network database table prefix. */
  const NETWORK_DB_TABLE_PREFIX = H5P_Tables::NETWORK_DB_TABLE_PREFIX;

  /** Name of network level H5P files directory, relative to uploads root. */
  const NETWORK_DIRECTORY_NAME = H5P_Network_Paths::NETWORK_DIRECTORY_NAME;

  /** Scope of tables every blog has its own of. */
  const TABLE_SCOPE_BLOG = H5P_Tables::SCOPE_BLOG;

  /** Scope of the library tables: per blog in local mode, shared in network mode. */
  const TABLE_SCOPE_LIBRARY = H5P_Tables::SCOPE_LIBRARY;

  /** Scope of tables that exist at network level only, in network mode. */
  const TABLE_SCOPE_NETWORK_ONLY = H5P_Tables::SCOPE_NETWORK_ONLY;

  /** Scope of tables shared by the whole installation in either mode. */
  const TABLE_SCOPE_GLOBAL = H5P_Tables::SCOPE_GLOBAL;

  /** Value a for_each_blog() callback returns to stop iteration over the remaining blogs. */
  const STOP_ITERATION = H5P_Blogs::STOP_ITERATION;

  /**
   * Whether network mode is enabled.
   *
   * @since 1.18.0
   * @return bool
   */
  public static function is_network_enabled() {
    return H5P_Network_Mode::is_network_enabled();
  }

  /**
   * Turn network mode on or off.
   *
   * @param bool $enabled Whether network mode should be enabled.
   */
  public static function set_network_enabled($enabled) {
    H5P_Network_Mode::set_network_enabled($enabled);
  }

  /**
   * Whether H5P Hub access is enabled on the current blog.
   *
   * @since 1.18.0
   * @return bool
   */
  public static function is_hub_enabled() {
    return H5P_Network_Mode::is_hub_enabled();
  }

  /**
   * Whether current user may manage libraries.
   *
   * @return bool
   */
  public static function current_user_can_manage_libraries() {
    return H5P_Network_Mode::current_user_can_manage_libraries();
  }

  /**
   * Whether current user may install recommended libraries.
   *
   * @return bool
   */
  public static function current_user_can_install_recommended_libraries() {
    return H5P_Network_Mode::current_user_can_install_recommended_libraries();
  }

  /**
   * Get path to network level H5P files folder.
   *
   * @return string
   */
  public static function get_h5p_network_path() {
    return H5P_Network_Paths::get_h5p_network_path();
  }

  /**
   * Get URL for network level H5P files folder.
   *
   * @return string
   */
  public static function get_h5p_network_url() {
    return H5P_Network_Paths::get_h5p_network_url();
  }

  /**
   * Update protocol of an absolute URL to HTTPS when the request is served over SSL.
   *
   * @since 1.18.0
   * @param string $url Absolute URL.
   * @return string URL with HTTPS protocol when served over SSL, otherwise unchanged.
   */
  public static function ensure_https($url) {
    return H5P_Network_Paths::ensure_https($url);
  }

  /**
   * Get path to the libraries folder, wherever the current mode stores them.
   *
   * @since 1.18.0
   * @return string
   */
  public static function get_libraries_path() {
    return H5P_Network_Paths::get_libraries_path();
  }

  /**
   * Get URL to the libraries folder, wherever the current mode stores them.
   *
   * @since 1.18.0
   * @return string
   */
  public static function get_libraries_url() {
    return H5P_Network_Paths::get_libraries_url();
  }

  /**
   * Build full database table name, including correct prefix.
   *
   * @param string $table_name Table name without prefix (e.g. 'h5p_libraries').
   * @return string Full table name.
   */
  public static function build_full_db_table_name($table_name) {
    return H5P_Tables::build_full_db_table_name($table_name);
  }

  /**
   * Return full table name using site local prefix.
   *
   * @param string $table_name Table name without prefix.
   * @return string Full table name.
   */
  public static function build_full_db_table_name_singlesite($table_name) {
    return H5P_Tables::build_full_db_table_name_singlesite($table_name);
  }

  /**
   * Return full table name for multisite installation.
   *
   * @param string $table_name Table name without prefix.
   * @return string Full table name.
   */
  public static function build_full_db_table_name_multisite($table_name) {
    return H5P_Tables::build_full_db_table_name_multisite($table_name);
  }

  /**
   * Get names of the tables of the given scopes, in registry order.
   *
   * @since 1.18.0
   * @param string ...$scopes One or more TABLE_SCOPE_* constants.
   * @return string[] Table names without prefix.
   */
  public static function get_table_names(...$scopes) {
    return H5P_Tables::get_table_names(...$scopes);
  }

  /**
   * Determine whether given database table exists on current blog.
   *
   * @since 1.18.0
   * @param string $table Full table name.
   * @return bool
   */
  public static function table_exists($table) {
    return H5P_Tables::table_exists($table);
  }

  /**
   * Build a comma separated list of %d placeholders for $wpdb->prepare().
   *
   * @since 1.18.0
   * @param int[] $ids Values the placeholders stand in for.
   * @return string One %d per id, separated by commas, for interpolation in a query.
   */
  public static function int_placeholders(array $ids) {
    return H5P_Tables::int_placeholders($ids);
  }

  /**
   * Get ids of all blogs in network.
   *
   * @since 1.18.0
   * @return int[] Blog ids, ordered by id.
   */
  public static function get_all_blog_ids() {
    return H5P_Blogs::get_all_blog_ids();
  }

  /**
   * Get number of blogs in network.
   *
   * @since 1.18.0
   * @return int Number of blogs.
   */
  public static function count_blogs() {
    return H5P_Blogs::count_blogs();
  }

  /**
   * Get ids of one page of blogs in network.
   *
   * @since 1.18.0
   * @param int $offset Number of blogs to skip.
   * @param int $number Maximum number of blogs to return.
   * @return int[] Blog ids, ordered by id.
   */
  public static function get_blog_ids_page($offset, $number) {
    return H5P_Blogs::get_blog_ids_page($offset, $number);
  }

  /**
   * Run callback in context of one blog.
   *
   * @since 1.18.0
   * @param int      $blog_id Blog to switch to for the duration of the callback.
   * @param callable $callback
   * @return mixed Result of the callback.
   */
  public static function in_blog($blog_id, callable $callback) {
    return H5P_Blogs::in_blog($blog_id, $callback);
  }

  /**
   * Run callback once per blog.
   *
   * @since 1.18.0
   * @param callable $callback Receives current blog id. Returning self::STOP_ITERATION stops.
   */
  public static function for_each_blog(callable $callback) {
    H5P_Blogs::for_each_blog($callback);
  }

  /**
   * Run callback once per blog of one page of blogs.
   *
   * @since 1.18.0
   * @param int      $offset   Number of blogs to skip.
   * @param int      $number   Maximum number of blogs to visit.
   * @param callable $callback Receives current blog id and its offset. Returning false stops.
   * @return int Offset after last visited blog.
   */
  public static function for_each_blog_page($offset, $number, callable $callback) {
    return H5P_Blogs::for_each_blog_page($offset, $number, $callback);
  }
}
