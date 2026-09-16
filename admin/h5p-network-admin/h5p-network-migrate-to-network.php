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
   * Maximum number of rows per multi-row INSERT.
   *
   * Keeps a generated statement well below max_allowed_packet, which defaults
   * to 1 MB on many servers.
   */
  const INSERT_CHUNK_SIZE = 500;

  /**
   * Offset of the temporary ID range used while remapping library IDs.
   *
   * Must be higher than any real library ID, and the offset plus the highest
   * new ID must still fit in library_id (INT UNSIGNED, max 4294967295).
   */
  const REMAP_TEMP_OFFSET = 1000000000;

  /**
   * Step that was being run when the migration failed, null if no step failed.
   *
   * Steps 1 and 2 only add network-level files and tables, so they can be
   * rolled back. Steps 3 and 4 delete blog-level data and cannot.
   *
   * @var int|null
   */
  protected $failed_step = null;

  /**
   * Column names of the network-level libraries table.
   *
   * The table is the same for every blog and its columns do not change during a
   * migration, so the lookup is cached instead of repeated per library.
   *
   * @var string[]|null
   */
  protected $network_library_columns = null;

  /**
   * Cached blog library id to network library id lookup table.
   *
   * @var array|null
   */
  protected $id_lookup_table = null;

  /**
   * Migrate all libraries (files and database) to network level.
   *
   * @throws Exception If something fails.
   */
  public function migrateToNetwork() {
    $this->failed_step = 1;
    $network_libraries_installed = $this->migrateLibrariesToNetwork();

    $this->failed_step = 2;
    $network_libraries_installed = $this->migrateDatabaseTablesToNetwork($network_libraries_installed);

    $this->failed_step = 3;
    $this->updateBlogsDatabase($network_libraries_installed);

    $this->failed_step = 4;
    $this->clearBlogsLibrariesAndCachedassets();

    $this->failed_step = null;
  }

  /**
   * Get the step that was being run when the migration failed.
   *
   * @return int|null Step number 1-4, or null if no step failed.
   */
  public function getFailedStep() {
    return $this->failed_step;
  }

  /**
   * Whether the failed step can be rolled back.
   *
   * Only steps 1 and 2 are non-destructive: they add the network libraries
   * directory and the network database tables without touching blog-level
   * data, so discarding both restores the pre-migration state. Step 3 drops
   * the blog tables and step 4 deletes the blog library files.
   *
   * @return bool True if a rollback of the failed step is safe.
   */
  public function isRollbackPossible() {
    return $this->failed_step !== null && $this->failed_step <= 2;
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
        return; // Blog has no H5P libraries directory yet, so nothing to migrate.
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
          if ($entry[0] === '.') {
            continue;
          }

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

    $skipped = array();

    H5PCommons::for_each_blog(function ($blog_id) use ($network_libraries_installed, &$skipped) {
      // Filter for libraries installed from blog
      $blog_libraries = array();
      foreach ($network_libraries_installed as $versioned_machine_name => $info) {
        if ($info['blog_id'] == $blog_id) {
          $blog_libraries[$info['versioned_machine_name']] = $info;
        }
      }

      foreach ($blog_libraries as $versioned_machine_name => $info) {
        $inserted = $this->insertBlogLibraryToNetwork($info['machine_name'], $info['version']);

        if ($inserted === false) {
          // No matching row in this blog's libraries table; legitimate, but
          // worth recording so a missing library can be traced afterwards.
          $skipped[] = "{$versioned_machine_name} (blog {$blog_id})";
          continue;
        }

        $this->insertBlogLibraryToNetworkLanguages(
          $inserted['blog_library_id'],
          $inserted['network_library_id']
        );
      }
    });

    if (!empty($skipped)) {
      error_log(
        'H5P network migration: no database entry found for ' . count($skipped)
        . ' library/libraries, skipped: ' . implode(', ', $skipped)
      );
    }

    $this->copyLibraryDependenciesToNetwork();

    return $network_libraries_installed;
  }

  /**
   * Build lookup table mapping blog-level library IDs to network-level library IDs.
   *
   * The result is cached: the network-level libraries are written once, before
   * the first call, and nothing afterwards changes the name and version of a
   * network library, so repeated calls would return the same mapping.
   *
   * @return array Keyed by blog_id, each value is associative array mapping blog library_id to network library_id.
   */
  public function buildIdLookupTable() {
    if ($this->id_lookup_table !== null) {
      return $this->id_lookup_table;
    }

    global $wpdb;

    // The network table is the same for every blog, so read it once and match
    // in PHP instead of querying it per blog library.
    $network_table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');
    $network_ids = array();
    foreach ($wpdb->get_results("SELECT id, name, major_version, minor_version FROM {$network_table_libraries}") as $entry) {
      $network_ids[$this->buildLibraryVersionKey($entry->name, $entry->major_version, $entry->minor_version)] = $entry->id;
    }

    $lookup = array();

    H5PCommons::for_each_blog(function ($blog_id) use (&$lookup, $network_ids) {
      global $wpdb;

      $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
      $blog_libraries = $wpdb->get_results(
        "SELECT id, name, major_version, minor_version FROM {$blog_table_libraries}"
      );

      foreach ($blog_libraries as $blog_library_entry) {
        $key = $this->buildLibraryVersionKey(
          $blog_library_entry->name,
          $blog_library_entry->major_version,
          $blog_library_entry->minor_version
        );

        if (isset($network_ids[$key])) {
          $lookup[$blog_id][$blog_library_entry->id] = $network_ids[$key];
        }
      }
    });

    $this->id_lookup_table = $lookup;

    return $lookup;
  }

  /**
   * Build the key identifying a library by name and major.minor version.
   *
   * @param string $name          Machine name of library.
   * @param int    $major_version Major version.
   * @param int    $minor_version Minor version.
   * @return string Key for matching blog libraries against network libraries.
   */
  protected function buildLibraryVersionKey($name, $major_version, $minor_version) {
    return $name . '|' . (int) $major_version . '|' . (int) $minor_version;
  }

  /**
   * Copy dependency entries (h5p_libraries_libraries) from each blog to the network-level table.
   *
   * The same dependency can come from several blogs. Duplicates are left to the
   * primary key instead of being looked up first, using the same
   * ON DUPLICATE KEY UPDATE that H5PWordPress::saveLibraryDependencies() uses.
   * Note the primary key is (library_id, required_library_id) and does not
   * include dependency_type, so a repeated pair updates the type.
   *
   * @throws Exception If a dependency entry cannot be inserted.
   */
  protected function copyLibraryDependenciesToNetwork() {
    $network_table_libraries_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_libraries');

    $lookup = $this->buildIdLookupTable();

    H5PCommons::for_each_blog(function ($blog_id) use ($network_table_libraries_libraries, $lookup) {
      global $wpdb;

      $blog_table_libraries_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_libraries');
      $dependencies = $wpdb->get_results(
        "SELECT library_id, required_library_id, dependency_type FROM {$blog_table_libraries_libraries}"
      );

      $values = array();
      $placeholders = array();

      foreach ($dependencies as $dependency) {
        $new_library_id = $lookup[$blog_id][$dependency->library_id] ?? null;
        $new_required_id = $lookup[$blog_id][$dependency->required_library_id] ?? null;

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

      // Chunked, so the statement cannot grow past max_allowed_packet.
      foreach (array_chunk($placeholders, self::INSERT_CHUNK_SIZE) as $index => $placeholders_chunk) {
        $values_chunk = array_slice(
          $values,
          $index * self::INSERT_CHUNK_SIZE * 3,
          count($placeholders_chunk) * 3
        );

        $result = $wpdb->query(
          $wpdb->prepare(
            "INSERT INTO {$network_table_libraries_libraries} (library_id, required_library_id, dependency_type)"
            . ' VALUES ' . implode(', ', $placeholders_chunk)
            . ' ON DUPLICATE KEY UPDATE dependency_type = VALUES(dependency_type)',
            $values_chunk
          )
        );

        if ($result === false) {
          throw new Exception(
            sprintf(
              /* translators: 1: blog id, 2: network table name */
              __('Failed to copy library dependencies of blog %1$d into "%2$s".', 'h5p'),
              $blog_id,
              $network_table_libraries_libraries
            )
          );
        }
      }
    });
  }

  /**
   * Update library_id references in blog-level h5p_contents tables.
   *
   * Replaces old blog-level library IDs with the corresponding network-level
   * library IDs using the lookup table built by buildIdLookupTable.
   */
  protected function updateBlogsLibraryIds() {
    $lookup = $this->buildIdLookupTable();

    H5PCommons::for_each_blog(function ($blog_id) use ($lookup) {
      $this->updateBlogContentsLibraryIds($blog_id, $lookup);
      $this->updateBlogContentsLibrariesLibraryIds($blog_id, $lookup);
    });
  }

  /**
   * Update library_id references in blog-level table.
   *
   * Two phases, one statement each. A single statement is not enough: the
   * mapping can move one library onto an ID another row still holds (e.g.
   * 18 => 4 together with 10 => 18), and h5p_contents_libraries has
   * PRIMARY KEY (content_id, library_id, dependency_type). Uniqueness is
   * checked while the statement updates row by row, not at the end, so the
   * first row moved onto a taken ID collides with the row that has not been
   * updated yet. Mapping into a temporary range first keeps every intermediate
   * value clear of the IDs still in use.
   *
   * @param string $table    Table name (without prefix).
   * @param array  $mappings Mapping of old_id => new_id.
   *
   * @throws Exception If the update fails.
   */
  protected function updateLibraryIdsInBlogTable($table, $mappings) {
    global $wpdb;

    $blog_table = H5PCommons::build_full_db_table_name_singlesite($table);

    $cases = array();
    $values = array();
    foreach ($mappings as $old_id => $new_id) {
      $cases[] = 'WHEN %d THEN %d';
      $values[] = (int) $old_id;
      $values[] = (int) $new_id + self::REMAP_TEMP_OFFSET;
    }

    $old_ids = array_map('intval', array_keys($mappings));

    // Phase 1: old ID -> new ID in the temporary range.
    $result = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$blog_table} SET library_id = CASE library_id "
        . implode(' ', $cases)
        . ' ELSE library_id END WHERE library_id IN ('
        . implode(', ', array_fill(0, count($old_ids), '%d')) . ')',
        array_merge($values, $old_ids)
      )
    );

    if ($result !== false) {
      // Phase 2: temporary range -> final new ID.
      $result = $wpdb->query(
        $wpdb->prepare(
          "UPDATE {$blog_table} SET library_id = library_id - %d WHERE library_id >= %d",
          self::REMAP_TEMP_OFFSET,
          self::REMAP_TEMP_OFFSET
        )
      );
    }

    if ($result === false) {
      throw new Exception(
        sprintf(
          /* translators: %s: blog table name */
          __('Failed to update library IDs in "%s".', 'h5p'),
          $blog_table
        )
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

    $this->updateBlogsLibraryIds();
    $this->dropBlogsTables();
  }

  /**
   * Drop all blog-level H5P tables that are not needed on every site.
   */
  protected function dropBlogsTables() {
    H5PCommons::for_each_blog(function () {
      global $wpdb;

      foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
        $wpdb->query("DROP TABLE IF EXISTS " . H5PCommons::build_full_db_table_name_singlesite($table_name));
      }
    });
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
   * Get the column names of the network-level libraries table.
   *
   * Cached, because the table name does not depend on the current blog and its
   * columns do not change while the migration runs.
   *
   * @return string[] Column names.
   */
  protected function getNetworkLibraryColumns() {
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
   *                     or false if the blog has no such library.
   * @throws Exception If the insert fails.
   */
  protected function insertBlogLibraryToNetwork($machine_name, $version) {
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
   * Copied inside the database, so the translations are never pulled into PHP
   * and sent back. They can be large, and a multi-row insert built in PHP would
   * risk exceeding max_allowed_packet.
   *
   * @param int $blog_library_id    Blog-level library ID.
   * @param int $network_library_id Network-level library ID.
   *
   * @throws Exception If the translations cannot be copied.
   */
  protected function insertBlogLibraryToNetworkLanguages($blog_library_id, $network_library_id) {
    global $wpdb;

    $blog_table_libraries_languages = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_languages');
    $network_table_libraries_languages = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_languages');

    $result = $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$network_table_libraries_languages} (library_id, language_code, translation)"
        . " SELECT %d, language_code, translation"
        . " FROM {$blog_table_libraries_languages} WHERE library_id = %d",
        $network_library_id,
        $blog_library_id
      )
    );

    if ($result === false) {
      throw new Exception(
        sprintf(
          /* translators: 1: blog library id, 2: network table name */
          __('Failed to copy translations for library %1$d into "%2$s".', 'h5p'),
          $blog_library_id,
          $network_table_libraries_languages
        )
      );
    }
  }
}
