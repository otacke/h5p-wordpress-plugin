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
   * Maximum rows per multi-row INSERT, keeping statements below 1 MB max_allowed_packet.
   */
  const INSERT_CHUNK_SIZE = 500;

  /**
   * Offset of temporary id range used while remapping library ids.
   *
   * Must exceed every real library id, and offset plus highest new id must fit in
   * library_id (INT UNSIGNED, max 4294967295).
   */
  const REMAP_TEMP_OFFSET = 1000000000;

  /**
   * Seconds of file work per request. Checked between blogs, so no blog is left half done.
   */
  const BATCH_TIMEOUT = 5;

  /**
   * Upper bound of blogs per batch; time budget usually ends batches earlier.
   */
  const BATCH_BLOG_LIMIT = 200;

  /**
   * Name of site option holding state of running migration.
   */
  const STATE_OPTION = 'h5p_network_migration_state';

  /**
   * Phase copying library files from blogs to network. Non-destructive.
   */
  const PHASE_COPY = 'copy';

  /**
   * Phase moving database to network. Destructive from here on.
   */
  const PHASE_DATABASE = 'database';

  /**
   * Phase remapping blog-level library ids and dropping the blogs' obsolete library tables.
   * Destructive, but resumable per blog and per stage.
   */
  const PHASE_REMAP = 'remap';

  /**
   * Phase deleting library files left on blogs. Destructive.
   */
  const PHASE_CLEAR = 'clear';

  /**
   * Phase marking finished migration.
   */
  const PHASE_DONE = 'done';

  /**
   * Step running when migration failed, null if no step failed.
   *
   * Steps 1 and 2 only add network files and tables, so they roll back. Steps 3 and 4 delete
   * blog data and cannot.
   *
   * @var int|null
   */
  protected $failed_step = null;

  /**
   * Column names of network-level libraries table, cached since they never change mid-migration.
   *
   * @var string[]|null
   */
  protected $network_library_columns = null;

  /**
   * Cached network library ids, keyed by library version key.
   *
   * @var array|null
   */
  protected $network_library_ids = null;

  /**
   * Get step that was running when migration failed.
   *
   * @return int|null Step number 1-4, or null if no step failed.
   */
  public function getFailedStep() {
    return $this->failed_step;
  }

  /**
   * Whether failed step can be rolled back: only steps 1 and 2 leave blog data untouched.
   *
   * @return bool True if rollback of failed step is safe.
   */
  public function isRollbackPossible() {
    return $this->failed_step !== null && $this->failed_step <= 2;
  }

  /**
   * Get state of running migration, or fresh state if none is stored.
   *
   * @return array State with 'phase', 'offset' and 'libraries'.
   */
  public static function getState() {
    $state = get_site_option(self::STATE_OPTION, null);

    if (!is_array($state) || !isset($state['phase'])) {
      return array(
        'phase'      => self::PHASE_COPY,
        'offset'     => 0,
        'libraries'  => array(),
      );
    }

    return $state;
  }

  /**
   * Store state of running migration.
   *
   * @param array $state State to store.
   */
  public static function setState($state) {
    update_site_option(self::STATE_OPTION, $state);
  }

  /**
   * Forget stored state, so next migration starts over instead of resuming.
   */
  public static function clearState() {
    delete_site_option(self::STATE_OPTION);
  }

  /**
   * Run one batch of migration and report progress. Call until returned progress reports done.
   *
   * @return array Progress with 'phase', 'percentage' and 'done'.
   * @throws Exception If step fails.
   */
  public function migrateNextBatch() {
    $state = self::getState();

    switch ($state['phase']) {
      case self::PHASE_COPY:
        $this->failed_step = 1;
        $state = $this->runCopyBatch($state);
        break;

      case self::PHASE_DATABASE:
        if (!empty($state['updating_blogs'])) {
          // Older code set this marker inside this phase while it updated the blogs, so such a state may
          // hold half-remapped blog databases. The migration cannot continue and has to be restarted.
          $this->failed_step = 3;
          throw new Exception(
            __('An earlier attempt failed while the blog databases were being updated, so the migration cannot continue.', 'h5p')
          );
        }

        // The remap that follows is resumable per blog, so this phase only runs step 2.
        $this->failed_step = 2;
        $this->migrateDatabaseTablesToNetwork($state['libraries']);
        $state['phase'] = self::PHASE_REMAP;
        $state['offset'] = 0;
        break;

      case self::PHASE_REMAP:
        $this->failed_step = 3;
        $state = $this->runRemapBatch($state);
        break;

      case self::PHASE_CLEAR:
        $this->failed_step = 4;
        $state = $this->runClearBatch($state);
        break;
    }

    if ($state['phase'] === self::PHASE_DONE) {
      $this->failed_step = null;
      // The network tables are now current, so check_for_updates() will not re-run them
      // on every blog until the plugin version changes.
      update_site_option('h5p_network_db_version', H5P_Plugin::VERSION);
      self::clearState();
    }
    else {
      self::setState($state);
    }

    return $this->buildProgress($state);
  }

  /**
   * Describe how far migration has got, as percentage so progress only moves forwards.
   *
   * Blogs are walked three times, copying, remapping and clearing, so blog count is not total.
   *
   * @param array $state Current migration state.
   * @return array Progress with 'phase', 'percentage' and 'done'.
   */
  protected function buildProgress($state) {
    $blogs = H5PCommons::count_blogs();
    $done = $state['phase'] === self::PHASE_DONE;

    switch ($state['phase']) {
      case self::PHASE_COPY:
        $passed = (int) $state['offset'];
        break;

      case self::PHASE_DATABASE:
        // Copying finished, remapping not started.
        $passed = $blogs;
        break;

      case self::PHASE_REMAP:
        $passed = $blogs + (int) $state['offset'];
        break;

      default:
        $passed = $blogs * 2 + (int) $state['offset'];
        break;
    }

    $total = $blogs * 3;

    return array(
      'phase'      => $state['phase'],
      'percentage' => ($done || $total <= 0) ? 100 : (int) round($passed / $total * 100),
      'done'       => $done,
    );
  }

  /**
   * Copy library files of next blogs to network.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If file system operation fails.
   */
  protected function runCopyBatch($state) {
    $this->ensureNetworkLibrariesDir();
    $this->ensureNetworkCachedassetsDir();

    $network_libraries_path = $this->getNetworkLibrariesPath();
    $libraries = $state['libraries'];
    $start = microtime(true);

    $offset = H5PCommons::for_each_blog_page(
      $state['offset'],
      self::BATCH_BLOG_LIMIT,
      function ($blog_id) use ($network_libraries_path, &$libraries, $start) {
        $this->copyBlogLibrariesToNetwork($blog_id, $network_libraries_path, $libraries);

        // Stop once budget is spent, leaving nothing half copied.
        return (microtime(true) - $start) <= self::BATCH_TIMEOUT;
      }
    );

    $state['libraries'] = $libraries;
    $state['offset'] = $offset;

    if ($offset >= H5PCommons::count_blogs()) {
      $state['phase'] = self::PHASE_DATABASE;
      $state['offset'] = 0;
    }

    return $state;
  }

  /**
   * Remap library ids and drop obsolete library tables of next blogs, batched and resumable per blog.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If a blog-level update fails.
   */
  protected function runRemapBatch($state) {
    $start = microtime(true);

    $offset = H5PCommons::for_each_blog_page(
      $state['offset'],
      self::BATCH_BLOG_LIMIT,
      function ($blog_id) use ($start, &$state) {
        $this->remapBlog($blog_id, $state);

        // Stop once budget is spent, leaving no blog half remapped.
        return (microtime(true) - $start) <= self::BATCH_TIMEOUT;
      }
    );

    $state['offset'] = $offset;

    if ($offset >= H5PCommons::count_blogs()) {
      $state['phase'] = self::PHASE_CLEAR;
      $state['offset'] = 0;
      unset($state['remap']);
    }

    return $state;
  }

  /**
   * Remap one blog's library ids and drop its obsolete library tables, resuming at its saved stage.
   *
   * Stages run in a fixed order and each is saved right after its statement, so re-running a
   * finished stage is a no-op: pass one only matches old ids, pass two only matches temporary
   * ids, and dropping tables is idempotent.
   *
   * @param int $blog_id Blog id.
   * @param array $state Migration state, updated with the blog's progress.
   * @throws Exception If a blog-level update fails.
   */
  protected function remapBlog($blog_id, &$state) {
    $stages = array(
      'h5p_contents:1',
      'h5p_contents:2',
      'h5p_contents_libraries:1',
      'h5p_contents_libraries:2',
      'dropped'
    );

    $recorded_stage = null;
    if (isset($state['remap']['blog']) && (int) $state['remap']['blog'] === $blog_id) {
      $recorded_stage = $state['remap']['stage'];
    }
    $recorded_index = array_search($recorded_stage, $stages, true);
    if ($recorded_index === false) {
      $recorded_index = -1;
    }

    // A blog that already dropped its tables is done, even if the batch that dropped them did not
    // get to persist its offset.
    $already_dropped = H5PCommons::in_blog($blog_id, function () use ($recorded_stage) {
      return $recorded_stage === 'dropped'
        && !H5PCommons::table_exists(H5PCommons::build_full_db_table_name_singlesite('h5p_libraries'));
    });
    if ($already_dropped) {
      return;
    }

    $mappings = $this->buildBlogIdLookup($blog_id);

    foreach ($stages as $index => $stage) {
      if ($index <= $recorded_index) {
        continue;
      }

      if ($stage === 'dropped') {
        $this->dropBlogLibraryTables($blog_id);
      }
      elseif (!empty($mappings)) {
        list($table_name, $pass) = explode(':', $stage);
        H5PCommons::in_blog($blog_id, function () use ($table_name, $pass, $mappings) {
          $blog_table = H5PCommons::build_full_db_table_name_singlesite($table_name);

          if ($pass === '1') {
            $this->remapLibraryIdsPass1($blog_table, $mappings);
          }
          else {
            $this->remapLibraryIdsPass2($blog_table);
          }
        });
      }

      $state['remap'] = array(
        'blog'  => $blog_id,
        'stage' => $stage
      );
      self::setState($state);
    }
  }

  /**
   * Delete migrated library files of next blogs.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If file cannot be deleted.
   */
  protected function runClearBatch($state) {
    WP_Filesystem();
    global $wp_filesystem;

    $start = microtime(true);

    $offset = H5PCommons::for_each_blog_page(
      $state['offset'],
      self::BATCH_BLOG_LIMIT,
      function () use ($wp_filesystem, $start) {
        $this->clearBlogLibrariesAndCachedassets($wp_filesystem);

        return (microtime(true) - $start) <= self::BATCH_TIMEOUT;
      }
    );

    $state['offset'] = $offset;

    if ($offset >= H5PCommons::count_blogs()) {
      $state['phase'] = self::PHASE_DONE;
    }

    return $state;
  }

  /**
   * Copy library directories of one blog to network level.
   *
   * Records copies in $network_libraries_installed, keeping only highest patch of each major.minor
   * across all blogs. Passed in and out so batches can carry it over several requests.
   *
   * @param int    $blog_id                     Blog to copy from.
   * @param string $network_libraries_path      Network-level libraries path.
   * @param array  $network_libraries_installed Record of copies so far, updated in place.
   *
   * @throws Exception If file system operation fails.
   */
  protected function copyBlogLibrariesToNetwork($blog_id, $network_libraries_path, &$network_libraries_installed) {
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

    // Network table auto-increment ids follow copy order, so process directories in the blog's
    // own table id order to keep the network ids aligned with the blog's.
    $library_directories = $this->orderLibraryDirectories($library_directories);

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

  /**
   * Order library directory names so directories with a row in the blog's h5p_libraries
   * table come first, in that table's id order; the remaining directories follow.
   *
   * Must run in the blog's context, since the table name resolves from the current blog.
   *
   * @param array $directory_names Directory names as returned by scandir().
   * @return array Ordered directory names.
   */
  protected function orderLibraryDirectories($directory_names) {
    $table = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');

    if (!H5PCommons::table_exists($table)) {
      return $directory_names;
    }

    global $wpdb;

    $rows = $wpdb->get_results("SELECT name, major_version, minor_version FROM {$table} ORDER BY id");
    if ($rows === null) {
      return $directory_names;
    }

    $present = array_flip($directory_names);
    $ordered = array();

    foreach ($rows as $row) {
      $name = $row->name . '-' . (int) $row->major_version . '.' . (int) $row->minor_version;

      if (isset($present[$name])) {
        $ordered[] = $name;
        unset($present[$name]);
      }
    }

    return array_merge($ordered, array_keys($present));
  }

  /**
   * Clear migrated H5P files of one blog, keeping directories themselves.
   *
   * Skips absent directories, so it can run again on blogs already cleared.
   *
   * @param WP_Filesystem_Base $wp_filesystem Filesystem to delete through.
   *
   * @throws Exception If existing file or directory cannot be deleted.
   */
  protected function clearBlogLibrariesAndCachedassets($wp_filesystem) {
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
   * @return array The array that was passed in, unmodified.
   *
   * @throws Exception If network table does not exist or insert fails.
   */
  public function migrateDatabaseTablesToNetwork($network_libraries_installed) {
    global $wpdb;

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
          // worth recording so missing libraries can be traced afterwards.
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
   * Build map of library version key to library id for the network-level libraries.
   *
   * Cached: network libraries are written once before first lookup, and nothing later changes
   * their name or version, so the map holds for the rest of the migration.
   *
   * @return array Map of library version key to network library id.
   */
  protected function buildNetworkLibraryIdMap() {
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
  protected function buildBlogIdLookup($blog_id) {
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
  protected function buildLibraryVersionKey($name, $major_version, $minor_version) {
    return $name . '|' . (int) $major_version . '|' . (int) $minor_version;
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
  protected function copyLibraryDependenciesToNetwork() {
    $network_table_libraries_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries_libraries');

    H5PCommons::for_each_blog(function ($blog_id) use ($network_table_libraries_libraries) {
      global $wpdb;

      $blog_table_libraries_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_libraries');

      if (!H5PCommons::table_exists($blog_table_libraries_libraries)) {
        return;
      }

      $dependencies = $wpdb->get_results(
        "SELECT library_id, required_library_id, dependency_type FROM {$blog_table_libraries_libraries}"
      );

      $lookup = $this->buildBlogIdLookup($blog_id);

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
  protected function remapLibraryIdsPass1($blog_table, $mappings) {
    global $wpdb;

    $cases = array();
    $values = array();
    foreach ($mappings as $old_id => $new_id) {
      $cases[] = 'WHEN %d THEN %d';
      $values[] = (int) $old_id;
      $values[] = (int) $new_id + self::REMAP_TEMP_OFFSET;
    }

    $old_ids = array_map('intval', array_keys($mappings));

    $result = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$blog_table} SET library_id = CASE library_id "
        . implode(' ', $cases)
        . ' ELSE library_id END WHERE library_id IN ('
        . implode(', ', array_fill(0, count($old_ids), '%d')) . ')',
        array_merge($values, $old_ids)
      )
    );

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
   * Pass 2 of the two-pass library id remap for one blog-level table: move the temporary range to
   * the final network ids.
   *
   * Re-running this is a no-op: no rows in the temporary range remain.
   *
   * @param string $blog_table Full name of the blog-level table.
   *
   * @throws Exception If the update fails.
   */
  protected function remapLibraryIdsPass2($blog_table) {
    global $wpdb;

    $result = $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$blog_table} SET library_id = library_id - %d WHERE library_id >= %d",
        self::REMAP_TEMP_OFFSET,
        self::REMAP_TEMP_OFFSET
      )
    );

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
   * Drop one blog's H5P library tables, which the network-level tables replace.
   *
   * @param int $blog_id Blog id.
   *
   * @throws Exception If a table cannot be dropped.
   */
  protected function dropBlogLibraryTables($blog_id) {
    H5PCommons::in_blog($blog_id, function () {
      global $wpdb;

      foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
        $blog_table = H5PCommons::build_full_db_table_name_singlesite($table_name);

        if ($wpdb->query("DROP TABLE IF EXISTS {$blog_table}") === false) {
          throw new Exception(
            sprintf(
              /* translators: %s: blog table name */
              __('Failed to drop table "%s".', 'h5p'),
              $blog_table
            )
          );
        }
      }
    });
  }

  /**
   * Get column names of network-level libraries table, cached since they never change mid-migration.
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
   *                     or false if blog has no such library.
   * @throws Exception If insert fails.
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
   * Copied inside database, so large translations are never pulled into PHP and sent back, which
   * would risk exceeding max_allowed_packet.
   *
   * @param int $blog_library_id    Blog-level library ID.
   * @param int $network_library_id Network-level library ID.
   *
   * @throws Exception If translations cannot be copied.
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
