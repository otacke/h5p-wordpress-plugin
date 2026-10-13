<?php
/**
 * H5P_Library_Restrictions
 *
 * Per-blog restrictions of runnable library versions in network mode.
 *
 * The network libraries table is shared by all blogs, so its restricted column cannot tell blogs apart.
 * Network mode therefore keeps one row per restricted library version and blog in its own network table
 * and ignores the restricted column, which migration to network level clears.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
class H5P_Library_Restrictions {

  /** Table name without prefix, resolved to network level by H5P_Tables. */
  const TABLE_NAME = 'h5p_libraries_restrictions';

  /** Maximum rows per multi-row INSERT, keeping statements below 1 MB max_allowed_packet. */
  const INSERT_CHUNK_SIZE = 500;

  /**
   * Get full name of network restrictions table.
   *
   * @return string
   */
  public static function get_table() {
    return H5P_Tables::build_full_db_table_name_multisite(self::TABLE_NAME);
  }

  /**
   * Create or update network restrictions table.
   *
   * dbDelta() requires wp-admin/includes/upgrade.php to be loaded.
   *
   * @param string $charset Charset clause to append to CREATE TABLE.
   */
  public static function update_table($charset) {
    $table = self::get_table();

    dbDelta("CREATE TABLE {$table} (
      library_id INT UNSIGNED NOT NULL,
      blog_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY  (library_id,blog_id),
      KEY blog_id (blog_id)
    ) {$charset};");
  }

  /**
   * Build SQL expression telling whether library is restricted on current blog, for use in SELECT lists.
   *
   * Yields '1' or '0' like the restricted column it replaces in network mode, so callers keep comparing
   * against '1'.
   *
   * @param string $alias Alias or full name of libraries table in the query.
   * @return string SQL expression, not aliased.
   */
  public static function restricted_column_sql($alias) {
    if (!H5P_Network_Mode::is_network_enabled()) {
      return "{$alias}.restricted";
    }

    $table = self::get_table();
    $blog_id = (int) get_current_blog_id();

    return "EXISTS(SELECT 1 FROM {$table} hlr WHERE hlr.library_id = {$alias}.id AND hlr.blog_id = {$blog_id})";
  }

  /**
   * Get ids of blogs a library version is restricted on.
   *
   * @param int $library_id Network library id.
   * @return int[]
   */
  public static function get_blog_ids($library_id) {
    global $wpdb;

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
      'SELECT blog_id FROM ' . self::get_table() . ' WHERE library_id = %d',
      $library_id
    )));
  }

  /**
   * Get ids of library versions restricted on a blog.
   *
   * @param int $blog_id Blog id.
   * @return int[]
   */
  public static function get_library_ids($blog_id) {
    global $wpdb;

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
      'SELECT library_id FROM ' . self::get_table() . ' WHERE blog_id = %d',
      $blog_id
    )));
  }

  /**
   * Count blogs each library version is restricted on.
   *
   * @return int[] Number of blogs, keyed by network library id. Unrestricted versions are left out.
   */
  public static function count_blogs_per_library() {
    global $wpdb;

    $counts = array();
    foreach ($wpdb->get_results('SELECT library_id, COUNT(*) AS blogs FROM ' . self::get_table() . ' GROUP BY library_id') as $row) {
      $counts[(int) $row->library_id] = (int) $row->blogs;
    }

    return $counts;
  }

  /**
   * Restrict or allow library versions on blogs, every given library on every given blog.
   *
   * @param int[] $library_ids Network library ids.
   * @param int[] $blog_ids    Blog ids.
   * @param bool  $restricted  True to restrict, false to allow.
   *
   * @throws Exception If database update fails.
   */
  public static function set_restricted(array $library_ids, array $blog_ids, $restricted) {
    global $wpdb;

    $library_ids = array_values(array_unique(array_map('intval', $library_ids)));
    $blog_ids = array_values(array_unique(array_map('intval', $blog_ids)));
    if (empty($library_ids) || empty($blog_ids)) {
      return;
    }

    $table = self::get_table();

    if (!$restricted) {
      $result = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table}"
        . ' WHERE library_id IN (' . H5P_Tables::int_placeholders($library_ids) . ')'
        . ' AND blog_id IN (' . H5P_Tables::int_placeholders($blog_ids) . ')',
        array_merge($library_ids, $blog_ids)
      ));
    }
    else {
      $pairs = array();
      foreach ($library_ids as $library_id) {
        foreach ($blog_ids as $blog_id) {
          $pairs[] = $library_id;
          $pairs[] = $blog_id;
        }
      }

      // Chunked, so restricting on every blog of a large network cannot grow past max_allowed_packet.
      foreach (array_chunk($pairs, self::INSERT_CHUNK_SIZE * 2) as $values) {
        $result = $wpdb->query($wpdb->prepare(
          "INSERT IGNORE INTO {$table} (library_id, blog_id) VALUES "
          . implode(', ', array_fill(0, count($values) / 2, '(%d, %d)')),
          $values
        ));

        if ($result === false) {
          break;
        }
      }
    }

    if ($result === false) {
      throw new Exception(__('Failed to save the restrictions.', 'h5p'));
    }
  }

  /**
   * Forget restrictions of a deleted library version.
   *
   * @param int $library_id Network library id.
   */
  public static function delete_for_library($library_id) {
    global $wpdb;

    $wpdb->delete(self::get_table(), array('library_id' => $library_id), array('%d'));
  }

  /**
   * Forget restrictions on a deleted blog.
   *
   * @param int $blog_id Blog id.
   */
  public static function delete_for_blog($blog_id) {
    global $wpdb;

    $wpdb->delete(self::get_table(), array('blog_id' => $blog_id), array('%d'));
  }
}
