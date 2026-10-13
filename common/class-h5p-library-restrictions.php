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
 * Also checks content against the restrictions in either mode, since core only uses them to hide content types
 * in the editor and never refuses to save content using them.
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
   * Get library versions the current user may not use on the current blog.
   *
   * Users who may create restricted content, as core's CREATE_RESTRICTED permission maps it, get none.
   *
   * @return string[] Titles of restricted versions, keyed by "machineName major.minor".
   */
  public static function get_restricted_for_current_blog() {
    global $wpdb;

    if (H5P_Network_Mode::current_user_can_manage_libraries()) {
      return array();
    }

    $table_libraries = H5P_Tables::build_full_db_table_name('h5p_libraries');
    $restricted = self::restricted_column_sql('hl');

    $restricted_libraries = array();
    foreach ($wpdb->get_results(
      "SELECT hl.name, hl.title, hl.major_version, hl.minor_version, {$restricted} AS restricted
         FROM {$table_libraries} hl
       HAVING restricted = 1"
    ) as $library) {
      $restricted_libraries["{$library->name} {$library->major_version}.{$library->minor_version}"] =
        "{$library->title} {$library->major_version}.{$library->minor_version}";
    }

    return $restricted_libraries;
  }

  /**
   * Find restricted library versions that content would newly use, as main library or as sub-content.
   *
   * Versions the content used before are not reported, so content using a version restricted later stays editable.
   *
   * @param array|string      $main_library     Main library, as array with machineName or name, majorVersion and
   *                                            minorVersion, or as "machineName major.minor".
   * @param array|object|string|null $params    Content parameters, decoded or as JSON.
   * @param array|string|null $old_main_library Main library before the change, null for new content.
   * @param array|object|string|null $old_params Content parameters before the change, null for new content.
   * @return string[] Titles of the restricted versions newly used, empty if none.
   */
  public static function find_introduced_restricted($main_library, $params, $old_main_library = null, $old_params = null) {
    $restricted = self::get_restricted_for_current_blog();
    if (empty($restricted)) {
      return array();
    }

    $used = self::collect_libraries($main_library, $params);
    $used_before = $old_main_library === null ? array() : self::collect_libraries($old_main_library, $old_params);

    return array_values(array_intersect_key($restricted, array_diff_key($used, $used_before)));
  }

  /**
   * Get the main library of an H5P package from its h5p.json.
   *
   * h5p.json names the main library without version, so the version comes from its preloaded dependency entry.
   *
   * @param array $h5p_json Decoded h5p.json, as associative array.
   * @return string "machineName major.minor", or empty string if h5p.json does not tell.
   */
  public static function get_package_main_library($h5p_json) {
    if (empty($h5p_json['mainLibrary']) || empty($h5p_json['preloadedDependencies'])) {
      return '';
    }

    foreach ((array) $h5p_json['preloadedDependencies'] as $dependency) {
      if (isset($dependency['machineName'], $dependency['majorVersion'], $dependency['minorVersion'])
        && $dependency['machineName'] === $h5p_json['mainLibrary']) {
        return "{$dependency['machineName']} " . (int) $dependency['majorVersion'] . '.' . (int) $dependency['minorVersion'];
      }
    }

    return '';
  }

  /**
   * Build the message refusing restricted library versions.
   *
   * @param string[] $titles Titles of the restricted versions, as find_introduced_restricted() returns them.
   * @return string
   */
  public static function get_refusal_message($titles) {
    return sprintf(
      /* translators: %s: comma separated content types with versions, e.g. "Multiple Choice 1.16" */
      __('Content using %s cannot be added on this site, as it is restricted.', 'h5p'),
      implode(', ', $titles)
    );
  }

  /**
   * Collect the library versions content uses: its main library and the library of every sub-content.
   *
   * @param array|string             $main_library See find_introduced_restricted().
   * @param array|object|string|null $params       See find_introduced_restricted().
   * @return true[] Keyed by "machineName major.minor".
   */
  private static function collect_libraries($main_library, $params) {
    $libraries = array();

    if (is_array($main_library)) {
      $name = isset($main_library['machineName']) ? $main_library['machineName'] : (isset($main_library['name']) ? $main_library['name'] : '');
      $main_library = "{$name} " . (int) $main_library['majorVersion'] . '.' . (int) $main_library['minorVersion'];
    }
    $libraries[$main_library] = true;

    if (is_string($params)) {
      $params = json_decode($params);
    }
    self::collect_sub_content_libraries($params, $libraries);

    return $libraries;
  }

  /**
   * Walk content parameters and record the library of every sub-content, which is a string "library" property.
   *
   * @param mixed  $value     Parameters, or part of them.
   * @param true[] $libraries Recorded libraries, keyed by "machineName major.minor", updated in place.
   */
  private static function collect_sub_content_libraries($value, &$libraries) {
    if (!is_array($value) && !is_object($value)) {
      return;
    }

    foreach ($value as $key => $item) {
      if ($key === 'library' && is_string($item)) {
        // Same pattern as H5PCore::libraryFromString(), and keyed the same way as the restricted versions.
        if (preg_match('/^([\w0-9\-\.]{1,255})[\-\ ]([0-9]{1,5})\.([0-9]{1,5})$/i', $item, $matches)) {
          $libraries["{$matches[1]} " . (int) $matches[2] . '.' . (int) $matches[3]] = true;
        }
      }
      else {
        self::collect_sub_content_libraries($item, $libraries);
      }
    }
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
