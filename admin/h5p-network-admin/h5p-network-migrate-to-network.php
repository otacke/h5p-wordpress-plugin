<?php

/**
 * H5P_Network_Migrate_To_Network
 *
 * Handles migration of H5P libraries from blog-level to network-level.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Migrate_To_Network extends H5P_Network_Admin_Base {

  /**
   * Migrate all libraries (files and database) to network level.
   *
   * @throws Exception If something fails.
   */
  public function migrateToNetwork() {
    // TODO: Sync blog h5p_contents and h5p_contents_libraries
    // TODO: CachedAssets!?!?
    // TODO: Delete libraries and database entries from blogs
    // TODO: Ensure all files can now be served from libraries_network

    $network_libraries_installed = $this->migrateLibrariesToNetwork();
    $this->migrateDatabaseTablesToNetwork($network_libraries_installed);
  }

  /**
   * Migrate library directories from all blog upload folders to network level.
   *
   * @return array Associative array keyed by machineName, each value containing 'version' and 'blog_id'.
   * @throws Exception If file system operation fails.
   */
  public function migrateLibrariesToNetwork() {
    $this->ensureNetworkLibrariesDir();

    $upload_directory = wp_upload_dir();
    $network_libraries_path = $this->getNetworkLibrariesPath();

    $network_libraries_installed = array();
    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      $libraries_directory = $this->getLibrariesDirForBlogId($blog_id, $upload_directory['basedir']);

      if (!is_dir($libraries_directory)) {
        throw new Exception(
          "Failed get hold of library directory: {$libraries_directory}"
        );
      }

      $library_directories = scandir($libraries_directory);
      if ($library_directories === false) {
        throw new Exception(
          "Failed to scan libraries directory: {$libraries_directory}"
        );
      }

      foreach ($library_directories as $library_directory_name) {
        $result = $this->processLibraryDirectory(
          $libraries_directory,
          $library_directory_name,
          $network_libraries_path,
          $blog_id
        );

        if ($result === null) {
          continue;
        }

        $existing_version = $network_libraries_installed[$result['versioned_machine_name']]['version'] ?? '';
        if ($existing_version !== '' && !$this->isLatestPatchVersion($existing_version, $result['version'])) {
          continue;
        }

        $network_libraries_installed[$result['versioned_machine_name']] = $result;
      }
    }

    return $network_libraries_installed;
  }

  /**
   * Ensure network-level libraries directory exists.
   *
   * @throws Exception If directory cannot be created.
   */
  protected function ensureNetworkLibrariesDir() {
    $network_libraries_path = $this->getNetworkLibrariesPath();
    if (is_dir($network_libraries_path)) {
      return;
    }

    if (!mkdir($network_libraries_path, 0755, true)) {
      throw new Exception(
        sprintf(
          /* translators: %s: network libraries directory path */
          __('Failed to create network libraries directory: %s', 'h5p'),
          $network_libraries_path
        )
      );
    }
  }

  /**
   * Get libraries directory path for given blog.
   *
   * @param int     $blog_id               Blog ID.
   * @param string  $upload_base_directory WordPress upload directory base path.
   * @return string Libraries directory for given blog.
   */
  protected function getLibrariesDirForBlogId($blog_id, $upload_base_directory) {
    return ($blog_id == 1) ?
      "{$upload_base_directory}/h5p/libraries" :
      "{$upload_base_directory}/sites/{$blog_id}/h5p/libraries";
  }

  /**
   * Process single library directory: read library.json, copy if needed.
   *
   * @param string $libraries_directory    Parent libraries directory path.
   * @param string $library_directory_name Library directory name.
   * @param string $network_libraries_path Network-level libraries path.
   * @param int    $blog_id                Blog ID.
   * @return array|null Associative array with relevant params.
   */
  protected function processLibraryDirectory(
    $libraries_directory,
    $library_directory_name,
    $network_libraries_path,
    $blog_id
  ) {
    if ($library_directory_name[0] === '.') {
      return null;
    }

    $library_path = "{$libraries_directory}/{$library_directory_name}";
    if (!is_dir($library_path)) {
      return null;
    }

    $library_json_path = "{$library_path}/library.json";
    if (!file_exists($library_json_path)) {
      return null;
    }

    $library_data = $this->readVersionedInfoFromLibraryJson($library_json_path);
    if ($library_data === null) {
      throw new Exception(
        "Failed to parse library.json from {$library_path}"
      );
    }

    $machine_name = $library_data['machineName'];
    $version = "{$library_data['majorVersion']}.{$library_data['minorVersion']}.{$library_data['patchVersion']}";
    $versioned_machine_name = "{$machine_name}-{$library_data['majorVersion']}.{$library_data['minorVersion']}";

    if (!$this->copyDirectory($library_path, $network_libraries_path)) {
      throw new Exception("Failed to copy library {$versioned_machine_name} from {$library_path}");
    }

    return array(
      'versioned_machine_name' => $versioned_machine_name,
      'machine_name'           => $machine_name,
      'version'                => $version,
      'blog_id'                => $blog_id,
    );
  }

  /**
   * Set up network-level libraries database table for blogs.
   *
   * @param array $network_libraries_installed Stuff that was installed on network level.
   * @return array Modified array with 'library_id' added to each entry.
   *
   * @throws Exception If network table does not exist or insert fails.
   */
  public function migrateDatabaseTablesToNetwork($network_libraries_installed) {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $network_table_libraries = $this->getNetworkLibrariesTableName();
    $network_table_libraries_cachedassets = $this->getNetworkLibrariesCachedAssetsTableName();
    $network_table_libraries_languages = $this->getNetworkLibrariesLanguagesTableName();
    $network_table_libraries_libraries = $this->getNetworkLibrariesLibrariesTableName();

    $this->createNetworkTable($network_table_libraries, "{$wpdb->prefix}h5p_libraries");
    $this->createNetworkTable($network_table_libraries_cachedassets, "{$wpdb->prefix}h5p_libraries_cachedassets");
    $this->createNetworkTable($network_table_libraries_languages, "{$wpdb->prefix}h5p_libraries_languages");
    $this->createNetworkTable($network_table_libraries_libraries, "{$wpdb->prefix}h5p_libraries_libraries");

    // Build a set of network table columns (excluding auto-increment id).
    $network_columns = $wpdb->get_col("DESCRIBE {$network_table_libraries}", 0);
    $network_columns_set = array_flip($network_columns);
    unset($network_columns_set['id']);

    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      try {
        // Filter for libraries installed from blog
        $blog_libraries = array();
        foreach ($network_libraries_installed as $versioned_machine_name => $info) {
          if ($info['blog_id'] == $blog_id) {
            $blog_libraries[$info['machine_name']] = $info;
          }
        }

        foreach ($blog_libraries as $versioned_machine_name => $info) {
          $network_library_id = $this->insertLibrary($info['machine_name'], $network_table_libraries);
          if (!$network_library_id) {
            continue;
          }

          $this->insertLibraryLanguages($info['machine_name'], $network_library_id, $network_table_libraries_languages);
        }

      }
      finally {
        restore_current_blog();
      }
    }

    $this->copyLibraryDependenciesToNetwork($network_table_libraries_libraries, $sites);

    return $network_libraries_installed;
  }

  /**
   * Build lookup table mapping blog-level library IDs to network-level library IDs.
   *
   * @return array Keyed by blog_id, each value is associative array mapping blog library_id to network library_id.
   */
  public function buildIdLookupTable() {
    global $wpdb;

    $network_table_libraries = $this->getNetworkLibrariesTableName();
    $lookup = array();

    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      try {

        $local_libraries = $wpdb->get_results(
          "SELECT id, name, major_version, minor_version FROM {$wpdb->prefix}h5p_libraries"
        );

        foreach ($local_libraries as $blog_library_entry) {
          $row = $wpdb->get_row(
            $wpdb->prepare(
              "SELECT id FROM {$network_table_libraries} WHERE name = %s AND major_version = %d AND minor_version = %d",
              $blog_library_entry->name,
              $blog_library_entry->major_version,
              $blog_library_entry->minor_version
            )
          );

          if ($row !== null) {
            $lookup[$blog_id][$blog_library_entry->id] = $row->id;
          }
        }

      }
      finally {
        restore_current_blog();
      }
    }

    return $lookup;
  }

  /**
   * Copy dependency entries (h5p_libraries_libraries) from each blog to the network-level table.
   *
   * @param string $network_table_name Network-level table name.
   * @param array  $sites              Array of blog IDs.
   */
  protected function copyLibraryDependenciesToNetwork($network_table_name, $sites) {
    global $wpdb;

    $lookup = $this->buildIdLookupTable();

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      $dependencies = $wpdb->get_results(
        "SELECT library_id, required_library_id, dependency_type FROM {$wpdb->prefix}h5p_libraries_libraries"
      );

      foreach ($dependencies as $dependency) {
        $new_library_id = $lookup[$blog_id][$dependency->library_id] ?? null;
        $new_required_id = $lookup[$blog_id][$dependency->required_library_id] ?? null;

        if ($new_library_id === null || $new_required_id === null) {
          continue;
        }

        // Skip if this dependency entry already exists in the network table. Same entry from different blogs
        $existing = $wpdb->get_row(
          $wpdb->prepare(
            "SELECT 1 FROM {$network_table_name} "
            . "WHERE library_id = %d AND required_library_id = %d "
            . "AND dependency_type = %s",
            $new_library_id,
            $new_required_id,
            $dependency->dependency_type
          )
        );
        if ($existing !== null) {
          continue;
        }

        $wpdb->insert(
          $network_table_name,
          array(
            'library_id'          => $new_library_id,
            'required_library_id' => $new_required_id,
            'dependency_type'     => $dependency->dependency_type,
          )
        );
      }

      restore_current_blog();
    }
  }

  /**
   * Create network-level table by copying the schema from blog-level table.
   *
   * @param string $network_table_name Network-level table name.
   * @param string $source_table_name  Existing blog-level table name.
   */
  protected function createNetworkTable($network_table_name, $source_table_name) {
    global $wpdb;

    $row = $wpdb->get_row(
      "SHOW CREATE TABLE {$source_table_name}",
      ARRAY_N
    );

    if ($row === null || count($row) < 2) {
      throw new Exception(
        "Failed to retrieve schema for source table: {$source_table_name}"
      );
    }

    $create_statement = $row[1];
    $create_statement = str_replace(
      "CREATE TABLE `{$source_table_name}`",
      "CREATE TABLE IF NOT EXISTS `{$network_table_name}`",
      $create_statement
    );

    $wpdb->query($create_statement);
  }

  /**
   * Insert library entry from blog-level table into network-level table.
   *
   * @param string $machine_name  Machine name of library.
   * @param string $network_table_libraries Network-level libraries table name.
   * @return int Network library ID.
   */
  protected function insertLibrary($machine_name, $network_table_libraries) {
    global $wpdb;

    $blog_library = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}h5p_libraries WHERE name = %s",
        $machine_name
      )
    );

    if ($blog_library === null) {
      return false;
    }

    // Map local columns to network columns, skipping 'id' (auto-increment) and columns not present locally.
    $insert_data = array();
    foreach ($wpdb->get_col("DESCRIBE {$network_table_libraries}", 0) as $col) {
      if ($col === 'id') {
        continue;
      }
      if (property_exists($blog_library, $col)) {
        $insert_data[$col] = $blog_library->{$col};
      }
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

    return $wpdb->insert_id;
  }

  /**
   * Copy language translations for library to network-level table.
   *
   * @param string $machine_name  Machine name of library.
   * @param int    $network_library_id Network library ID.
   * @param string $network_table_libraries_languages Network-level languages table name.
   */
  protected function insertLibraryLanguages(
    $machine_name,
    $network_library_id,
    $network_table_libraries_languages
  ) {
    global $wpdb;

    $blog_library = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}h5p_libraries WHERE name = %s",
        $machine_name
      )
    );

    if ($blog_library === null) {
      return;
    }

    $languages = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT language_code, translation FROM {$wpdb->prefix}h5p_libraries_languages WHERE library_id = %d",
        $blog_library->id
      )
    );
    foreach ($languages as $language) {
      $wpdb->insert(
        $network_table_libraries_languages,
        array(
          'library_id'    => $network_library_id,
          'language_code' => $language->language_code,
          'translation'   => $language->translation,
        )
      );
    }
  }
}
