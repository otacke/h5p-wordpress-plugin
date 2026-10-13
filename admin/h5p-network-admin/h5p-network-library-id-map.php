<?php

/**
 * H5P_Network_Library_Id_Map
 *
 * Library ids during the migration to network level: which network library id each blog library id becomes, and
 * the two-pass remap of the blog content tables to those ids.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Id_Map extends H5P_Network_Admin_Base {

  /**
   * Offset of temporary id range used while remapping library ids.
   *
   * Must exceed every real library id, and offset plus highest new id must fit in
   * library_id (INT UNSIGNED, max 4294967295).
   */
  const REMAP_TEMP_OFFSET = 1000000000;

  /**
   * Cached network library ids, keyed by library version key.
   *
   * @var array|null
   */
  protected $network_library_ids = null;

  /**
   * Build map of library version key to library id for the network-level libraries.
   *
   * Cached: network libraries are written once before first lookup, and nothing later changes
   * their name or version, so the map holds for the rest of the migration.
   *
   * @return array Map of library version key to network library id.
   */
  public function buildNetworkLibraryIdMap() {
    if ($this->network_library_ids !== null) {
      return $this->network_library_ids;
    }

    global $wpdb;

    $network_table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');
    $network_ids = array();
    foreach ($wpdb->get_results("SELECT id, name, major_version, minor_version FROM {$network_table_libraries}") as $entry) {
      $network_ids[$this->buildLibraryVersionKey($entry->name, $entry->major_version, $entry->minor_version)] = $entry->id;
    }

    $this->network_library_ids = $network_ids;

    return $network_ids;
  }

  /**
   * Build map of one blog's library ids to their network-level library ids.
   *
   * @param int $blog_id Blog id.
   * @return array Map of old library id to network library id. Empty for a blog without H5P
   *   library tables, which is a blog that never used H5P or whose tables were already dropped.
   */
  public function buildBlogIdLookup($blog_id) {
    return H5PCommons::in_blog($blog_id, function () {
      $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');

      if (!H5PCommons::table_exists($blog_table_libraries)) {
        return array();
      }

      global $wpdb;

      // Network table is the same for every blog, so read it once and match in PHP instead of
      // querying it per blog library.
      $network_ids = $this->buildNetworkLibraryIdMap();
      $mappings = array();
      foreach ($wpdb->get_results("SELECT id, name, major_version, minor_version FROM {$blog_table_libraries}") as $entry) {
        $key = $this->buildLibraryVersionKey($entry->name, $entry->major_version, $entry->minor_version);

        if (isset($network_ids[$key])) {
          $mappings[$entry->id] = $network_ids[$key];
        }
      }

      return $mappings;
    });
  }

  /**
   * Build key identifying library by name and major.minor version.
   *
   * @param string $name          Machine name of library.
   * @param int    $major_version Major version.
   * @param int    $minor_version Minor version.
   * @return string Key for matching blog libraries against network libraries.
   */
  public function buildLibraryVersionKey($name, $major_version, $minor_version) {
    return $name . '|' . (int) $major_version . '|' . (int) $minor_version;
  }

  /**
   * Pass 1 of the two-pass library id remap for one blog-level table: move the old ids to a
   * temporary range.
   *
   * Two statements are required per table: a mapping can move one library onto an id another row
   * still holds (e.g. 18 => 4 while 10 => 18), and h5p_contents_libraries has
   * PRIMARY KEY (content_id, library_id, dependency_type). Uniqueness is checked row by row while
   * updating, not at end, so moving onto taken ids collides. The temporary range avoids that.
   *
   * Re-running this is a no-op: rows already moved hold temporary ids, not old ids, so they no
   * longer match the WHERE.
   *
   * @param string $blog_table Full name of the blog-level table.
   * @param array  $mappings   Map of old id to new id.
   *
   * @throws Exception If the update fails.
   */
  public function remapLibraryIdsPass1($blog_table, $mappings) {
    global $wpdb;

    $cases = array();
    $values = array();
    foreach ($mappings as $old_id => $new_id) {
      $cases[] = 'WHEN %d THEN %d';
      $values[] = (int) $old_id;
      $values[] = (int) $new_id + self::REMAP_TEMP_OFFSET;
    }

    $old_ids = array_map('intval', array_keys($mappings));

    $this->query_or_throw(
      $wpdb->prepare(
        "UPDATE {$blog_table} SET library_id = CASE library_id "
        . implode(' ', $cases)
        . ' ELSE library_id END WHERE library_id IN ('
        . implode(', ', array_fill(0, count($old_ids), '%d')) . ')',
        array_merge($values, $old_ids)
      ),
      sprintf(
        /* translators: %s: blog table name */
        __('Failed to update library IDs in "%s".', 'h5p'),
        $blog_table
      )
    );
  }

  /**
   * Pass 2 of the two-pass library id remap for one blog-level table: move the temporary range to
   * the final network ids.
   *
   * Re-running this is a no-op: no rows in the temporary range remain.
   *
   * @param string $blog_table Full name of the blog-level table.
   *
   * @throws Exception If the update fails.
   */
  public function remapLibraryIdsPass2($blog_table) {
    global $wpdb;

    $this->query_or_throw(
      $wpdb->prepare(
        "UPDATE {$blog_table} SET library_id = library_id - %d WHERE library_id >= %d",
        self::REMAP_TEMP_OFFSET,
        self::REMAP_TEMP_OFFSET
      ),
      sprintf(
        /* translators: %s: blog table name */
        __('Failed to update library IDs in "%s".', 'h5p'),
        $blog_table
      )
    );
  }
}
