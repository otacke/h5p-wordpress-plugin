<?php

/**
 * H5P_Network_Migrate_To_Local
 *
 * Migrates H5P libraries from network level back to blog level, then removes network files and tables.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Migrate_To_Local extends H5P_Network_Admin_Base {

  /**
   * Migrate all libraries from network level back to blog level and verify every blog got a
   * complete copy.
   *
   * The network data itself (files, tables, schema version) is left in place: the caller must
   * only remove it after the network mode flag has been switched off. A failure throws, so the
   * network state stays consistent and a retry starts clean: copies drop their destination first.
   *
   * @throws Exception If a copy or the verification fails.
   */
  public function migrateToLocal() {
    $this->copyNetworkLibrariesToBlogs();
    $this->copyDatabaseTablesToBlogs();
    $this->verifyBlogsMatchNetwork();
  }

  /**
   * Copy network-level database tables to every blog.
   *
   * @throws Exception If a table cannot be copied or truncated.
   */
  protected function copyDatabaseTablesToBlogs() {
    H5PCommons::for_each_blog(function () {
      global $wpdb;

      foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
        $this->copyTableFromNetwork(
          H5PCommons::build_full_db_table_name_singlesite($table_name),
          H5PCommons::build_full_db_table_name_multisite($table_name)
        );
      }

      // Rebuilt by createCachedAssets() after migration.
      $cachedassets_table = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_cachedassets');

      if ($wpdb->query("TRUNCATE TABLE {$cachedassets_table}") === false) {
        throw new Exception(
          sprintf(
            /* translators: %s: blog table name */
            __('Failed to truncate "%s".', 'h5p'),
            $cachedassets_table
          )
        );
      }
    });
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

    if (!$this->createTableFromExisting($network_table_name, $blog_table_name, true)) {
      throw new Exception(
        sprintf(
          /* translators: %s: blog table name */
          __('Failed to create table "%s" from the network-level copy.', 'h5p'),
          $blog_table_name
        )
      );
    }

    $columns = $wpdb->get_col("DESCRIBE `{$blog_table_name}`", 0);
    $column_list = implode(', ', $columns);

    $result = $wpdb->query(
      "INSERT INTO `{$blog_table_name}` ({$column_list}) SELECT {$column_list} FROM `{$network_table_name}`"
    );

    if ($result === false) {
      throw new Exception(
        sprintf(
          /* translators: 1: blog table name, 2: network table name */
          __('Failed to copy rows from "%2$s" into "%1$s".', 'h5p'),
          $blog_table_name,
          $network_table_name
        )
      );
    }
  }

  /**
   * Copy network-level library files into every blog's libraries directory.
   *
   * @throws Exception If a directory cannot be created or a library cannot be copied.
   */
  protected function copyNetworkLibrariesToBlogs() {
    $network_libraries_path = $this->getNetworkLibrariesPath();
    if (!is_dir($network_libraries_path)) {
      return;
    }

    H5PCommons::for_each_blog(function () use ($network_libraries_path) {
      $upload_directory = wp_upload_dir();
      $target_dir = "{$upload_directory['basedir']}/h5p/libraries";

      if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
        throw new Exception(
          sprintf(
            /* translators: %s: directory path */
            __('Failed to create directory "%s".', 'h5p'),
            $target_dir
          )
        );
      }

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
    });
  }

  /**
   * Verify that every blog holds a complete copy of the network-level library data.
   *
   * Row counts of the three library tables must match the network tables, and the library
   * folders must exist wherever the network had library files.
   *
   * @throws Exception If a blog's copy is incomplete.
   */
  protected function verifyBlogsMatchNetwork() {
    global $wpdb;

    $table_names = array('h5p_libraries', 'h5p_libraries_libraries', 'h5p_libraries_languages');
    $network_counts = array();
    foreach ($table_names as $table_name) {
      $network_counts[$table_name] = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . H5PCommons::build_full_db_table_name_multisite($table_name)
      );
    }

    $libraries_exist = is_dir($this->getNetworkLibrariesPath());

    H5PCommons::for_each_blog(function ($blog_id) use ($table_names, $network_counts, $libraries_exist) {
      global $wpdb;

      $table_libraries = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');

      if (!H5PCommons::table_exists($table_libraries)) {
        return;
      }

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

  /**
   * Delete network-level files directory.
   *
   * @throws Exception If the directory cannot be deleted.
   */
  public function deleteNetworkFilesDirectory() {
    $network_lib_base = $this->getH5PNetworkPath();

    if (is_dir($network_lib_base)) {
      H5PCore::deleteFileTree($network_lib_base);

      if (is_dir($network_lib_base)) {
        throw new Exception(
          sprintf(
            /* translators: %s: directory path */
            __('Failed to delete directory "%s".', 'h5p'),
            $network_lib_base
          )
        );
      }
    }
  }

  /**
   * Drop network-level database tables.
   */
  public function dropNetworkTables() {
    global $wpdb;

    foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
      $wpdb->query("DROP TABLE IF EXISTS " . H5PCommons::build_full_db_table_name_multisite($table_name));
    }
  }
}
