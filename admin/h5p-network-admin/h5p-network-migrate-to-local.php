<?php

/**
 * H5P_Network_Migrate_To_Local
 *
 * Migrates H5P libraries from network level back to blog level, in batches over several requests, then removes
 * network files and tables.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Migrate_To_Local extends H5P_Network_Admin_Base {

  /**
   * Name of site option holding state of running migration back to local level.
   */
  const STATE_OPTION = 'h5p_network_demigration_state';

  /**
   * Phase copying the network library files into every blog.
   */
  const PHASE_FILES = 'files';

  /**
   * Phase copying the network library tables into every blog.
   */
  const PHASE_TABLES = 'tables';

  /**
   * Phase verifying that every blog got a complete copy.
   */
  const PHASE_VERIFY = 'verify';

  /**
   * Phase marking finished migration.
   */
  const PHASE_DONE = 'done';

  /**
   * Get the store of the migration state.
   *
   * @return H5P_Network_Migration_State
   */
  public static function state() {
    return new H5P_Network_Migration_State(self::STATE_OPTION, array(
      'phase'  => self::PHASE_FILES,
      'offset' => 0,
    ));
  }

  /**
   * Run one batch of the migration back to local level and report progress. Call until progress reports done.
   *
   * Copies the network library files and tables into every blog that uses H5P, then verifies the copies. The
   * network data itself (files, tables, schema version) is left in place: finish() removes it, after the caller
   * switched the network mode off. A failure throws and forgets the state, so the network state stays consistent
   * and a retry starts clean: copies drop their destination first.
   *
   * @param bool $restart Forget a stored state first, so a new run never resumes an abandoned one.
   * @return array Progress with 'phase', 'percentage' and 'done'.
   * @throws Exception If a copy or the verification fails.
   */
  public function migrateNextBatch($restart = false) {
    $store = self::state();
    if ($restart) {
      $store->clear();
    }
    $state = $store->get();

    try {
      switch ($state['phase']) {
        case self::PHASE_FILES:
          $state = $this->runFilesBatch($state);
          break;

        case self::PHASE_TABLES:
          $state = $this->runTablesBatch($state);
          break;

        case self::PHASE_VERIFY:
          $this->verifyBlogsMatchNetwork();
          $state['phase'] = self::PHASE_DONE;
          break;
      }
    }
    catch (Exception $exception) {
      $store->clear();
      throw $exception;
    }

    if ($state['phase'] === self::PHASE_DONE) {
      $store->clear();
    }
    else {
      $store->set($state);
    }

    return $this->buildProgress($state);
  }

  /**
   * Remove the network data once the network mode is switched off, and forget the network schema version.
   *
   * Never fails: the site is already local then, so leftover network data is inert and only logged.
   */
  public function finish() {
    try {
      $this->removeNetworkData();

      // The network tables are gone, so the network schema version is meaningless.
      delete_site_option('h5p_network_db_version');
    }
    catch (Exception $exception) {
      error_log('H5P local demigration cleanup failed: ' . $exception->getMessage());
    }
  }

  /**
   * Describe how far the migration has got, as percentage so progress only moves forwards.
   *
   * Blogs are walked twice, copying files and copying tables; the verification counts as the end.
   *
   * @param array $state Current migration state.
   * @return array Progress with 'phase', 'percentage' and 'done'.
   */
  protected function buildProgress($state) {
    $blogs = H5PCommons::count_blogs();
    $done = $state['phase'] === self::PHASE_DONE;

    switch ($state['phase']) {
      case self::PHASE_FILES:
        $passed = (int) $state['offset'];
        break;

      case self::PHASE_TABLES:
        $passed = $blogs + (int) $state['offset'];
        break;

      default:
        $passed = $blogs * 2;
        break;
    }

    $total = $blogs * 2 + 1;

    return array(
      'phase'      => $state['phase'],
      'percentage' => ($done || $total <= 0) ? 100 : (int) round($passed / $total * 100),
      'done'       => $done,
    );
  }

  /**
   * Copy the network library files into the next blogs.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If a directory cannot be created or a library cannot be copied.
   */
  protected function runFilesBatch($state) {
    $network_libraries_path = $this->getNetworkLibrariesPath();
    if (!is_dir($network_libraries_path)) {
      $state['phase'] = self::PHASE_TABLES;
      $state['offset'] = 0;
      return $state;
    }

    return $this->runBlogBatch($state, function () use ($network_libraries_path) {
      if ($this->blogUsesH5P()) {
        $this->copyNetworkLibrariesToBlog($network_libraries_path);
      }
    }, self::PHASE_TABLES);
  }

  /**
   * Copy the network library tables into the next blogs.
   *
   * @param array $state Current migration state.
   * @return array Updated migration state.
   * @throws Exception If a table cannot be copied or truncated.
   */
  protected function runTablesBatch($state) {
    // Absent if network mode was enabled by a version without per-blog restrictions; the restricted column
    // copied from the network then stays as it is.
    $has_restrictions = H5PCommons::table_exists(H5P_Library_Restrictions::get_table());

    return $this->runBlogBatch($state, function ($blog_id) use ($has_restrictions) {
      if ($this->blogUsesH5P()) {
        $this->copyDatabaseTablesToBlog($blog_id, $has_restrictions);
      }
    }, self::PHASE_VERIFY);
  }

  /**
   * Whether the current blog uses H5P, i.e. has its content tables. A blog that never did needs no library copy:
   * like before network mode, it gets its tables when H5P first runs there.
   *
   * @return bool
   */
  protected function blogUsesH5P() {
    return H5PCommons::table_exists(H5PCommons::build_full_db_table_name_singlesite('h5p_contents'));
  }

  /**
   * Copy network-level database tables to the current blog.
   *
   * @param int  $blog_id          Blog id.
   * @param bool $has_restrictions Whether the network restrictions table exists.
   * @throws Exception If a table cannot be copied or truncated.
   */
  protected function copyDatabaseTablesToBlog($blog_id, $has_restrictions) {
    foreach (H5PCommons::get_table_names(H5PCommons::TABLE_SCOPE_LIBRARY) as $table_name) {
      $this->copyTableFromNetwork(
        H5PCommons::build_full_db_table_name_singlesite($table_name),
        H5PCommons::build_full_db_table_name_multisite($table_name)
      );
    }

    if ($has_restrictions) {
      $this->copyRestrictionsToBlog($blog_id);
    }

    // Rebuilt by createCachedAssets() after migration.
    $cachedassets_table = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_cachedassets');

    $this->query_or_throw(
      "TRUNCATE TABLE {$cachedassets_table}",
      sprintf(
        /* translators: %s: blog table name */
        __('Failed to truncate "%s".', 'h5p'),
        $cachedassets_table
      )
    );
  }

  /**
   * Set restricted column of the blog's libraries table from the network restrictions of that blog.
   *
   * Library ids are preserved by copyTableFromNetwork(), so the network ids apply to the blog table as they are.
   * Must run in the blog's context, after its libraries table was copied.
   *
   * @param int $blog_id Blog id.
   * @throws Exception If the libraries table cannot be updated.
   */
  protected function copyRestrictionsToBlog($blog_id) {
    global $wpdb;

    $blog_table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');
    $restrictions_table = H5P_Library_Restrictions::get_table();

    $this->query_or_throw(
      $wpdb->prepare(
        "UPDATE {$blog_table_libraries} hl SET hl.restricted = EXISTS("
        . "SELECT 1 FROM {$restrictions_table} hlr WHERE hlr.library_id = hl.id AND hlr.blog_id = %d)",
        $blog_id
      ),
      sprintf(
        /* translators: %s: blog table name */
        __('Failed to copy library restrictions into "%s".', 'h5p'),
        $blog_table_libraries
      )
    );
  }

  /**
   * Create blog-level table from network-level source and copy its rows, preserving ids.
   *
   * @param string $blog_table_name    Destination blog-level table name.
   * @param string $network_table_name Source network-level table name.
   * @throws Exception If the table cannot be created or its rows copied.
   */
  protected function copyTableFromNetwork($blog_table_name, $network_table_name) {
    global $wpdb;

    $this->createTableFromExisting($network_table_name, $blog_table_name);

    $columns = $wpdb->get_col("DESCRIBE `{$blog_table_name}`", 0);
    $column_list = implode(', ', $columns);

    $this->query_or_throw(
      "INSERT INTO `{$blog_table_name}` ({$column_list}) SELECT {$column_list} FROM `{$network_table_name}`",
      sprintf(
        /* translators: 1: blog table name, 2: network table name */
        __('Failed to copy rows from "%2$s" into "%1$s".', 'h5p'),
        $blog_table_name,
        $network_table_name
      )
    );
  }

  /**
   * Copy network-level library files into the current blog's libraries directory.
   *
   * @param string $network_libraries_path Network-level libraries path.
   * @throws Exception If a directory cannot be created or a library cannot be copied.
   */
  protected function copyNetworkLibrariesToBlog($network_libraries_path) {
    $upload_directory = wp_upload_dir();
    $target_dir = "{$upload_directory['basedir']}/h5p/libraries";
    $this->ensureDir($target_dir);

    foreach (scandir($network_libraries_path) as $library_directory_name) {
      if ($library_directory_name[0] === '.') {
        continue;
      }

      $library_path = "{$network_libraries_path}/{$library_directory_name}";
      if (!is_dir($library_path)) {
        continue;
      }

      if (!$this->copyDirectory($library_path, $target_dir)) {
        throw new Exception(
          sprintf(
            /* translators: 1: library name, 2: blog directory path */
            __('Failed to copy library "%1$s" into "%2$s".', 'h5p'),
            $library_directory_name,
            $target_dir
          )
        );
      }
    }
  }

  /**
   * Verify that every blog using H5P holds a complete copy of the network-level library data.
   *
   * Row counts of the three library tables must match the network tables, and the library
   * folders must exist wherever the network had library files.
   *
   * @throws Exception If a blog's copy is incomplete.
   */
  protected function verifyBlogsMatchNetwork() {
    global $wpdb;

    // The cached assets are not compared: copyDatabaseTablesToBlog() empties them, createCachedAssets() rebuilds.
    $table_names = array_diff(H5PCommons::get_table_names(H5PCommons::TABLE_SCOPE_LIBRARY), array('h5p_libraries_cachedassets'));
    $network_counts = array();
    foreach ($table_names as $table_name) {
      $network_counts[$table_name] = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . H5PCommons::build_full_db_table_name_multisite($table_name)
      );
    }

    $libraries_exist = is_dir($this->getNetworkLibrariesPath());

    // Blogs without a libraries table did not use H5P and got no copy.
    H5PCommons::for_each_blog_with_tables(array('h5p_libraries'), function ($blog_id) use ($table_names, $network_counts, $libraries_exist) {
      global $wpdb;

      foreach ($table_names as $table_name) {
        $blog_table = H5PCommons::build_full_db_table_name_singlesite($table_name);
        $blog_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$blog_table}");

        if ($blog_count !== $network_counts[$table_name]) {
          throw new Exception(
            sprintf(
              /* translators: 1: blog id, 2: table name, 3: blog row count, 4: network row count */
              __('Migration to local level is incomplete: blog %1$d table "%2$s" has %3$d rows, the network has %4$d.', 'h5p'),
              $blog_id,
              $table_name,
              $blog_count,
              $network_counts[$table_name]
            )
          );
        }
      }

      if ($libraries_exist) {
        $upload_directory = wp_upload_dir();
        $libraries_dir = "{$upload_directory['basedir']}/h5p/libraries";

        if (!is_dir($libraries_dir)) {
          throw new Exception(
            sprintf(
              /* translators: 1: blog id, 2: directory path */
              __('Migration to local level is incomplete: blog %1$d has no library directory "%2$s".', 'h5p'),
              $blog_id,
              $libraries_dir
            )
          );
        }
      }
    });
  }
}
