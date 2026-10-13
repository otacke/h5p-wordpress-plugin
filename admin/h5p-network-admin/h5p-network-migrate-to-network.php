<?php

/**
 * H5P_Network_Migrate_To_Network
 *
 * Handles migration of H5P libraries from blog-level to network-level.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Migrate_To_Network extends H5P_Network_Admin_Base {

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
   * Phase remapping blog library ids, moving restrictions, dropping blog tables; destructive, resumable per blog/stage.
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
   * Step running when migration failed, or null; steps 1-2 only add network data and roll back, 3-4 delete blog data.
   *
   * @var int|null
   */
  protected $failed_step = null;

  /**
   * Library directories of the migration, created on first use.
   *
   * @var H5P_Network_Library_Files|null
   */
  private $files = null;

  /**
   * Library id map of the migration, created on first use.
   *
   * @var H5P_Network_Library_Id_Map|null
   */
  private $id_map = null;

  /**
   * Library table copy of the migration, created on first use.
   *
   * @var H5P_Network_Library_Table_Copy|null
   */
  private $table_copy = null;

  /**
   * Get the library directories helper. Created lazily, as subclasses may not call a parent constructor.
   *
   * @return H5P_Network_Library_Files
   */
  protected function files() {
    return $this->files ?? ($this->files = new H5P_Network_Library_Files());
  }

  /**
   * Get the library id map, which caches the network ids for the rest of the migration.
   *
   * @return H5P_Network_Library_Id_Map
   */
  protected function idMap() {
    return $this->id_map ?? ($this->id_map = new H5P_Network_Library_Id_Map());
  }

  /**
   * Get the library table copy helper.
   *
   * @return H5P_Network_Library_Table_Copy
   */
  protected function tableCopy() {
    return $this->table_copy ?? ($this->table_copy = new H5P_Network_Library_Table_Copy($this->idMap()));
  }

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
   * Roll back a failed migration if the failed step allows it: remove the network data and forget the state.
   *
   * @return bool Whether the migration was rolled back.
   */
  public function rollback() {
    if (!$this->isRollbackPossible()) {
      return false;
    }

    try {
      $this->removeNetworkData();
      self::clearState();
      return true;
    }
    catch (Exception $exception) {
      error_log('H5P network migration rollback: ' . $exception->getMessage());
      return false;
    }
  }

  /**
   * Get the store of the migration state.
   *
   * @return H5P_Network_Migration_State
   */
  public static function state() {
    return new H5P_Network_Migration_State(self::STATE_OPTION, array(
      'phase'      => self::PHASE_COPY,
      'offset'     => 0,
      'libraries'  => array(),
    ));
  }

  /**
   * Get state of running migration, or fresh state if none is stored.
   *
   * @return array State with 'phase', 'offset' and 'libraries'.
   */
  public static function getState() {
    return self::state()->get();
  }

  /**
   * Store state of running migration.
   *
   * @param array $state State to store.
   */
  public static function setState($state) {
    self::state()->set($state);
  }

  /**
   * Forget stored state, so next migration starts over instead of resuming.
   */
  public static function clearState() {
    self::state()->clear();
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
          // Older code set this marker while updating blogs, so databases may be half-remapped; restart required.
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
      // Network tables are current, so check_for_updates() won't re-run them on every blog until version changes.
      update_site_option('h5p_network_db_version', H5P_Plugin::VERSION);
      self::clearState();
    }
    else {
      self::setState($state);
    }

    return $this->buildProgress($state);
  }

  /**
   * Describe progress as forward-only percentage: blogs are walked three times (copy, remap, clear).
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
    $this->ensureDir($this->getNetworkLibrariesPath());
    $this->ensureDir($this->getNetworkCachedassetsPath());

    $network_libraries_path = $this->getNetworkLibrariesPath();

    return $this->runBlogBatch($state, function ($blog_id, &$state) use ($network_libraries_path) {
      $this->files()->copyBlogLibrariesToNetwork($blog_id, $network_libraries_path, $state['libraries']);
    }, self::PHASE_DATABASE);
  }

  /**
   * Remap library ids and drop obsolete library tables of next blogs, batched and resumable per blog.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If a blog-level update fails.
   */
  protected function runRemapBatch($state) {
    $state = $this->runBlogBatch($state, function ($blog_id, &$state) {
      $this->remapBlog($blog_id, $state);
    }, self::PHASE_CLEAR);

    if ($state['phase'] === self::PHASE_CLEAR) {
      unset($state['remap']);
    }

    return $state;
  }

  /**
   * Remap one blog's library ids and drop its obsolete library tables, resuming at its saved stage.
   *
   * Stages run in fixed order, saved after each statement; rerun no-op (passes match old/temp ids; drop idempotent).
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
      'restrictions',
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

    // Blog that already dropped its tables is done, even if dropping batch did not persist its offset.
    $already_dropped = H5PCommons::in_blog($blog_id, function () use ($recorded_stage) {
      return $recorded_stage === 'dropped'
        && !H5PCommons::table_exists(H5PCommons::build_full_db_table_name_singlesite('h5p_libraries'));
    });
    if ($already_dropped) {
      return;
    }

    $mappings = $this->idMap()->buildBlogIdLookup($blog_id);

    foreach ($stages as $index => $stage) {
      if ($index <= $recorded_index) {
        continue;
      }

      if ($stage === 'dropped') {
        $this->dropBlogLibraryTables($blog_id);
      }
      elseif ($stage === 'restrictions') {
        $this->copyBlogRestrictionsToNetwork($blog_id, $mappings);
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

    return $this->runBlogBatch($state, function () use ($wp_filesystem) {
      $this->files()->clearBlogLibrariesAndCachedassets($wp_filesystem);
    }, self::PHASE_DONE);
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

    foreach (H5PCommons::get_table_names(H5PCommons::TABLE_SCOPE_LIBRARY) as $table_name) {
      $this->createTableFromExisting(
        H5PCommons::build_full_db_table_name_singlesite($table_name),
        H5PCommons::build_full_db_table_name_multisite($table_name)
      );
    }

    $this->tableCopy()->createNetworkRestrictionsTable();

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
        $inserted = $this->tableCopy()->insertBlogLibraryToNetwork($info['machine_name'], $info['version']);

        if ($inserted === false) {
          // No matching row in blog's libraries table; legitimate, but recorded to trace missing libraries.
          $skipped[] = "{$versioned_machine_name} (blog {$blog_id})";
          continue;
        }

        $this->tableCopy()->insertBlogLibraryToNetworkLanguages(
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

    $this->tableCopy()->copyLibraryDependenciesToNetwork();

    return $network_libraries_installed;
  }


  /**
   * Restrict on network level, for one blog, the library versions restricted in that blog's libraries table.
   *
   * Shared network libraries table can't hold per-blog restrictions; re-run is no-op (existing rows ignored).
   *
   * @param int   $blog_id  Blog id.
   * @param array $mappings Map of blog library id to network library id.
   *
   * @throws Exception If restrictions cannot be stored.
   */
  protected function copyBlogRestrictionsToNetwork($blog_id, $mappings) {
    $restricted_ids = H5PCommons::in_blog($blog_id, function () {
      global $wpdb;

      $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
      if (!H5PCommons::table_exists($blog_table_libraries)) {
        return array();
      }

      return $wpdb->get_col("SELECT id FROM {$blog_table_libraries} WHERE restricted = 1");
    });

    $network_ids = array();
    foreach ($restricted_ids as $restricted_id) {
      if (isset($mappings[$restricted_id])) {
        $network_ids[] = $mappings[$restricted_id];
      }
    }

    H5P_Library_Restrictions::set_restricted($network_ids, array($blog_id), true);
  }





  /**
   * Pass 1 of library id remap, see H5P_Network_Library_Id_Map::remapLibraryIdsPass1(); seam for fault injection.
   *
   * @param string $blog_table Full name of the blog-level table.
   * @param array  $mappings   Map of old id to new id.
   * @throws Exception If the update fails.
   */
  protected function remapLibraryIdsPass1($blog_table, $mappings) {
    $this->idMap()->remapLibraryIdsPass1($blog_table, $mappings);
  }

  /**
   * Pass 2 of library id remap, see H5P_Network_Library_Id_Map::remapLibraryIdsPass2(); seam for fault injection.
   *
   * @param string $blog_table Full name of the blog-level table.
   * @throws Exception If the update fails.
   */
  protected function remapLibraryIdsPass2($blog_table) {
    $this->idMap()->remapLibraryIdsPass2($blog_table);
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

      foreach (H5PCommons::get_table_names(H5PCommons::TABLE_SCOPE_LIBRARY) as $table_name) {
        $blog_table = H5PCommons::build_full_db_table_name_singlesite($table_name);

        $this->query_or_throw(
          "DROP TABLE IF EXISTS {$blog_table}",
          sprintf(
            /* translators: %s: blog table name */
            __('Failed to drop table "%s".', 'h5p'),
            $blog_table
          )
        );
      }
    });
  }



}
