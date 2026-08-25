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
    $network_libraries_installed = $this->migrateLibrariesToNetwork();
    $network_libraries_installed = $this->migrateDatabaseTablesToNetwork($network_libraries_installed);
    $this->updateBlogsDatabase($network_libraries_installed);
    $this->clearBlogsLibrariesAndCachedassets();

    // TODO: Generate cached assets for network level
  }

  /**
   * Migrate library directories from all blog upload folders to network level.
   *
   * @return array Associative array keyed by machineName, each value containing 'version' and 'blog_id'.
   * @throws Exception If file system operation fails.
   */
  public function migrateLibrariesToNetwork() {
    $this->ensureNetworkLibrariesDir();
    $this->ensureNetworkCachedassetsDir();

    $network_libraries_path = $this->getNetworkLibrariesPath();

    $network_libraries_installed = array();

    H5PCommons::for_each_blog(function ($blog_id) use ($network_libraries_path, &$network_libraries_installed) {
      $upload_directory = wp_upload_dir();
      $libraries_directory = "{$upload_directory['basedir']}/h5p/libraries";

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
    });

    return $network_libraries_installed;
  }

  /**
   * Clear blog-level H5P content (libraries and cachedassets) that was migrated
   * to network level.
   *
   * Empties each site's h5p/libraries and h5p/cachedassets directories, keeping
   * the directories themselves. The primary site's directories are separate from
   * the network-level h5p_network directory, so it is included.
   *
   * @throws Exception If an existing file or directory cannot be deleted.
   */
  protected function clearBlogsLibrariesAndCachedassets() {
    WP_Filesystem();
    global $wp_filesystem;

    H5PCommons::for_each_blog(function () use ($wp_filesystem) {
      $upload_directory = wp_upload_dir();
      $directories = array(
        "{$upload_directory['basedir']}/h5p/libraries",
        "{$upload_directory['basedir']}/h5p/cachedassets",
      );

      foreach ($directories as $directory) {
        if (!$wp_filesystem->is_dir($directory)) {
          continue;
        }

        foreach (scandir($directory) as $entry) {
          $entry_path = "{$directory}/{$entry}";

          if (is_dir($entry_path)) {
            $deleted = $wp_filesystem->rmdir($entry_path, true);
          } else {
            $deleted = $wp_filesystem->delete($entry_path);
          }

          if (!$deleted) {
            throw new Exception("Failed to delete: {$entry_path}");
          }
        }
      }
    });
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
   * Ensure network-level cachedassets directory exists.
   *
   * @throws Exception If directory cannot be created.
   */
  protected function ensureNetworkCachedassetsDir() {
    $network_cachedassets_path = $this->getNetworkCachedassetsPath();
    if (is_dir($network_cachedassets_path)) {
      return;
    }

    if (!mkdir($network_cachedassets_path, 0755, true)) {
      throw new Exception(
        sprintf(
          /* translators: %s: network cachedassets directory path */
          __('Failed to create network cachedassets directory: %s', 'h5p'),
          $network_cachedassets_path
        )
      );
    }
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

    foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
      $this->createTableFromExisting(
        H5PCommons::build_full_db_table_name_singlesite($table_name),
        H5PCommons::build_full_db_table_name_multisite($table_name),
        true
      );
    }

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
          $network_library_id = $this->insertBlogLibraryToNetwork($info['machine_name']);

          if (!$network_library_id) {
            continue;
          }

          $this->insertBlogLibraryToNetworkLanguages($info['machine_name'], $network_library_id);
        }

      }
      finally {
        restore_current_blog();
      }
    }

    $this->copyLibraryDependenciesToNetwork();

    return $network_libraries_installed;
  }

  /**
   * Build lookup table mapping blog-level library IDs to network-level library IDs.
   *
   * @return array Keyed by blog_id, each value is associative array mapping blog library_id to network library_id.
   */
  public function buildIdLookupTable() {
    global $wpdb;

    $network_table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');
    $lookup = array();

    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      try {
        $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
        $blog_libraries = $wpdb->get_results(
          "SELECT id, name, major_version, minor_version FROM {$blog_table_libraries}"
        );

        foreach ($blog_libraries as $blog_library_entry) {
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
   */
  protected function copyLibraryDependenciesToNetwork() {
    global $wpdb;

    $network_table_libraries_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_libraries');

    $lookup = $this->buildIdLookupTable();

    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      $blog_table_libraries_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_libraries');
      $dependencies = $wpdb->get_results(
        "SELECT library_id, required_library_id, dependency_type FROM {$blog_table_libraries_libraries}"
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
            "SELECT 1 FROM {$network_table_libraries_libraries} "
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
          $network_table_libraries_libraries,
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
   * Update library_id references in blog-level h5p_contents tables.
   *
   * Replaces old blog-level library IDs with the corresponding network-level
   * library IDs using the lookup table built by buildIdLookupTable.
   *
   * @param string $network_table_libraries Network-level libraries table name.
   * @param array  $sites Array of blog IDs.
   */
  protected function updateBlogsLibraryIds($network_table_libraries, $sites) {
    $lookup = $this->buildIdLookupTable();
    foreach ($sites as $blog_id) {
      try {
        switch_to_blog($blog_id);

        $this->updateBlogContentsLibraryIds($blog_id, $lookup);
        $this->updateBlogContentsLibrariesLibraryIds($blog_id, $lookup);
      }
      finally {
        restore_current_blog();
      }
    }
  }

  /**
   * Update library_id references in blog-level table using 2-phase sentinel update to avoid cascading mapping issues.
   *
   * @param string $table Table name (without prefix).
   * @param string $where Column used in the WHERE clause of Phase 1.
   * @param array  $mappings  Mapping of old_id => new_id.
   */
  protected function updateLibraryIdsInBlogTable($table, $mappings) {
    global $wpdb;
    $temp_offset = 1000000000; // Large to avoid conflicts, but not fail-safe!

    $blog_table = H5PCommons::build_full_db_table_name_singlesite($table);

    // Phase 1: old_id -> temp(new_id)
    foreach ($mappings as $old_id => $new_id) {
      $wpdb->update(
        $blog_table,
        array('library_id' => (int) $new_id + $temp_offset),
        array('library_id' => $old_id),
        array('%d'),
        array('%d')
      );
    }

    // Phase 2: temp(new_id) -> final new_id
    foreach ($mappings as $old_id => $new_id) {
      $wpdb->update(
        $blog_table,
        array('library_id' => (int) $new_id),
        array('library_id' => (int) $new_id + $temp_offset),
        array('%d'),
        array('%d')
      );
    }
  }

  /**
   * Update library_id references in blog-level h5p_contents table.
   *
   * Replaces old blog-level library IDs with the corresponding network-level
   * library IDs using the lookup table.
   *
   * @param int   $blog_id Blog ID.
   * @param array $lookup  Lookup table mapping blog_id → (old_id → new_id).
   */
  protected function updateBlogContentsLibraryIds($blog_id, $lookup) {
    $mappings = $lookup[$blog_id] ?? [];
    if (empty($mappings)) {
      return;
    }

    $this->updateLibraryIdsInBlogTable('h5p_contents', $mappings);
  }

  /**
   * Update library_id references in blog-level h5p_contents_libraries table.
   *
   * @param int   $blog_id Blog ID.
   * @param array $lookup  Lookup table mapping blog_id → (old_id → new_id).
   */
  protected function updateBlogContentsLibrariesLibraryIds($blog_id, $lookup) {
    $mappings = $lookup[$blog_id] ?? [];
    if (empty($mappings)) {
      return;
    }
    $this->updateLibraryIdsInBlogTable('h5p_contents_libraries', $mappings);
  }

  /**
   * Update blog-level library_id references and drop obsolete tables.
   *
   * @param array $network_libraries_installed Stuff that was installed on network level.
   */
  protected function updateBlogsDatabase($network_libraries_installed) {
    global $wpdb;

    $sites = get_sites(array('fields' => 'ids'));
    $this->updateBlogsLibraryIds($network_libraries_installed, $sites);
    $this->dropBlogsTables();
  }

  /**
   * Drop all blog-level H5P tables that are not needed on every site.
   */
  protected function dropBlogsTables() {
    global $wpdb;

    $sites = get_sites(array('fields' => 'ids'));
    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);
      try {
        foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
          $wpdb->query("DROP TABLE IF EXISTS " . H5PCommons::build_full_db_table_name_singlesite($table_name));
        }
      }
      finally {
        restore_current_blog();
      }
    }
  }

  /**
   * Create network-level table by copying the schema from blog-level table.
   *
   * @param string $network_table_name Network-level table name.
   * @param string $source_table_name  Existing blog-level table name.
   */
  protected function createNetworkTable($network_table_name, $source_table_name) {
    $this->createTableFromExisting(
      $source_table_name,
      $network_table_name,
      true
    );
  }

  /**
   * Insert library entry from blog-level table into network-level table.
   *
   * @param string $machine_name Machine name of library.
   * @return int Network library ID.
   */
  protected function insertBlogLibraryToNetwork($machine_name) {
    global $wpdb;

    $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
    $network_table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');

    $blog_library = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$blog_table_libraries} WHERE name = %s",
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
   * @param string $machine_name     Machine name of library.
   * @param int    $network_library_id Network library ID.
   */
  protected function insertBlogLibraryToNetworkLanguages($machine_name, $network_library_id) {
    global $wpdb;

    $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
    $blog_library = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT id FROM {$blog_table_libraries} WHERE name = %s",
        $machine_name
      )
    );

    if ($blog_library === null) {
      return;
    }

    $blog_table_libraries_languages = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_languages');
    $languages = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT language_code, translation FROM {$blog_table_libraries_languages} WHERE library_id = %d",
        $blog_library->id
      )
    );
    foreach ($languages as $language) {
      $wpdb->insert(
        H5PCommons::build_full_db_table_name_multisite('h5p_libraries_languages'),
        array(
          'library_id'    => $network_library_id,
          'language_code' => $language->language_code,
          'translation'   => $language->translation,
        )
      );
    }
  }
}
