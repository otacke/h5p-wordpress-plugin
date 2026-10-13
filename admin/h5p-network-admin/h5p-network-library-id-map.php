<?php

/**
 * H5P_Network_Library_Id_Map
 *
 * Maps blog library ids to network library ids during migration and remaps blog tables in two passes.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Id_Map extends H5P_Network_Admin_Base {

  /**
   * Temporary id range offset for remapping: must exceed all real ids; offset + highest new id must fit INT UNSIGNED.
   */
  const REMAP_TEMP_OFFSET = 1000000000;

  /**
   * Cached network library ids, keyed by library version key.
   *
   * @var array|null
   */
  protected $network_library_ids = null;

  /**
   * Build map of library version key to network library id; cached, as names/versions don't change after first lookup.
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
   * @return array Map of old library id to network library id; empty if blog has no H5P library tables.
   */
  public function buildBlogIdLookup($blog_id) {
    return H5PCommons::in_blog($blog_id, function () {
      $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');

      if (!H5PCommons::table_exists($blog_table_libraries)) {
        return array();
      }

      global $wpdb;

      // Network table is same for every blog, so read it once and match in PHP.
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
   * Pass 1 of two-pass library id remap of one blog table: move old ids to temporary range.
   *
   * Direct move collides: target ids may still be held (18 => 4 while 10 => 18) and h5p_contents_libraries'
   * PRIMARY KEY is checked row by row while updating. Re-running is no-op: moved rows no longer match WHERE.
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
   * Pass 2 of two-pass library id remap of one blog table: move temporary range to final ids; re-running is no-op.
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
