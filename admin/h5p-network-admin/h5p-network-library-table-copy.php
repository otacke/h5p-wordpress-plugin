<?php

/**
 * H5P_Network_Library_Table_Copy
 *
 * Library rows during the migration to network level: copying each blog's library rows, translations and
 * dependencies into the network tables, and creating the network restrictions table.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Table_Copy extends H5P_Network_Admin_Base {

  /**
   * Maximum rows per multi-row INSERT, keeping statements below 1 MB max_allowed_packet.
   */
  const INSERT_CHUNK_SIZE = 500;

  /**
   * Column names of network-level libraries table, cached since they never change mid-migration.
   *
   * @var string[]|null
   */
  protected $network_library_columns = null;

  /**
   * Maps blog library ids to network library ids, for the dependencies.
   *
   * @var H5P_Network_Library_Id_Map
   */
  protected $id_map;

  /**
   * @param H5P_Network_Library_Id_Map $id_map Id map of the running migration.
   */
  public function __construct(H5P_Network_Library_Id_Map $id_map) {
    $this->id_map = $id_map;
  }

  /**
   * Create empty network-level restrictions table, filled per blog by the remap phase. Drops it first,
   * so a repeated attempt starts clean like the tables created from the blog tables.
   *
   * @throws Exception If table cannot be created.
   */
  public function createNetworkRestrictionsTable() {
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table = H5P_Library_Restrictions::get_table();
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
    H5P_Library_Restrictions::update_table(H5P_Plugin::determine_charset());

    if (!H5PCommons::table_exists($table)) {
      throw new Exception(
        sprintf(
          /* translators: %s: network table name */
          __('Failed to create table "%s".', 'h5p'),
          $table
        )
      );
    }
  }

  /**
   * Copy dependency entries (h5p_libraries_libraries) from every blog to network-level table.
   *
   * Same dependency can come from several blogs, so duplicates are left to primary key via
   * ON DUPLICATE KEY UPDATE, as H5PWordPress::saveLibraryDependencies() does. That key is
   * (library_id, required_library_id) without dependency_type, so repeated pairs update type.
   *
   * @throws Exception If dependency entry cannot be inserted.
   */
  public function copyLibraryDependenciesToNetwork() {
    $network_table_libraries_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_libraries');

    H5PCommons::for_each_blog_with_tables(array('h5p_libraries_libraries'), function ($blog_id) use ($network_table_libraries_libraries) {
      global $wpdb;

      $blog_table_libraries_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_libraries');

      $dependencies = $wpdb->get_results(
        "SELECT library_id, required_library_id, dependency_type FROM {$blog_table_libraries_libraries}"
      );

      $lookup = $this->id_map->buildBlogIdLookup($blog_id);

      $values = array();
      $placeholders = array();

      foreach ($dependencies as $dependency) {
        $new_library_id = $lookup[$dependency->library_id] ?? null;
        $new_required_id = $lookup[$dependency->required_library_id] ?? null;

        if ($new_library_id === null || $new_required_id === null) {
          continue;
        }

        $placeholders[] = '(%d, %d, %s)';
        $values[] = $new_library_id;
        $values[] = $new_required_id;
        $values[] = $dependency->dependency_type;
      }

      if (empty($placeholders)) {
        return;
      }

      // Chunked, so statements cannot grow past max_allowed_packet.
      foreach (array_chunk($placeholders, self::INSERT_CHUNK_SIZE) as $index => $placeholders_chunk) {
        $values_chunk = array_slice(
          $values,
          $index * self::INSERT_CHUNK_SIZE * 3,
          count($placeholders_chunk) * 3
        );

        $this->query_or_throw(
          $wpdb->prepare(
            "INSERT INTO {$network_table_libraries_libraries} (library_id, required_library_id, dependency_type)"
            . ' VALUES ' . implode(', ', $placeholders_chunk)
            . ' ON DUPLICATE KEY UPDATE dependency_type = VALUES(dependency_type)',
            $values_chunk
          ),
          sprintf(
            /* translators: 1: blog id, 2: network table name */
            __('Failed to copy library dependencies of blog %1$d into "%2$s".', 'h5p'),
            $blog_id,
            $network_table_libraries_libraries
          )
        );
      }
    });
  }

  /**
   * Get column names of network-level libraries table, cached since they never change mid-migration.
   *
   * @return string[] Column names.
   */
  public function getNetworkLibraryColumns() {
    global $wpdb;

    if ($this->network_library_columns === null) {
      $this->network_library_columns = $wpdb->get_col(
        'DESCRIBE ' . H5PCommons::build_full_db_table_name_multisite('h5p_libraries'),
        0
      );
    }

    return $this->network_library_columns;
  }

  /**
   * Insert library entry from blog-level table into network-level table.
   *
   * @param string $machine_name Machine name of library.
   * @param string $version Semantic version major.minor.patch
   *
   * @return array|false Array with 'blog_library_id' and 'network_library_id',
   *                     or false if blog has no such library.
   * @throws Exception If insert fails.
   */
  public function insertBlogLibraryToNetwork($machine_name, $version) {
    global $wpdb;

    $version_splits = explode('.', $version);

    $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
    $network_table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');

    $blog_library = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$blog_table_libraries} WHERE name = %s AND major_version = %d AND minor_version = %d AND patch_version = %d",
        $machine_name,
        (int) $version_splits[0],
        (int) $version_splits[1],
        (int) $version_splits[2]
      )
    );

    if ($blog_library === null) {
      return false;
    }

    // Map local columns to network columns, skipping 'id' (auto-increment) and columns not present locally.
    $insert_data = array();
    foreach ($this->getNetworkLibraryColumns() as $col) {
      if ($col === 'id') {
        continue;
      }
      if (property_exists($blog_library, $col)) {
        $insert_data[$col] = $blog_library->{$col};
      }
    }
    // Restrictions differ per blog, so they move to the network restrictions table in the remap phase.
    if (array_key_exists('restricted', $insert_data)) {
      $insert_data['restricted'] = 0;
    }
    $result = $wpdb->insert($network_table_libraries, $insert_data);

    if ($result === false) {
      throw new Exception(
        sprintf(
          /* translators: 1: machine name, 2: network table name */
          __('Failed to insert library "%1$s" into "%2$s".', 'h5p'),
          $machine_name,
          $network_table_libraries
        )
      );
    }

    return array(
      'blog_library_id'    => (int) $blog_library->id,
      'network_library_id' => (int) $wpdb->insert_id,
    );
  }

  /**
   * Copy language translations for library to network-level table.
   *
   * Copied inside database, so large translations are never pulled into PHP and sent back, which
   * would risk exceeding max_allowed_packet.
   *
   * @param int $blog_library_id    Blog-level library ID.
   * @param int $network_library_id Network-level library ID.
   *
   * @throws Exception If translations cannot be copied.
   */
  public function insertBlogLibraryToNetworkLanguages($blog_library_id, $network_library_id) {
    global $wpdb;

    $blog_table_libraries_languages = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_languages');
    $network_table_libraries_languages = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_languages');

    $this->query_or_throw(
      $wpdb->prepare(
        "INSERT INTO {$network_table_libraries_languages} (library_id, language_code, translation)"
        . " SELECT %d, language_code, translation"
        . " FROM {$blog_table_libraries_languages} WHERE library_id = %d",
        $network_library_id,
        $blog_library_id
      ),
      sprintf(
        /* translators: 1: blog library id, 2: network table name */
        __('Failed to copy translations for library %1$d into "%2$s".', 'h5p'),
        $blog_library_id,
        $network_table_libraries_languages
      )
    );
  }
}
