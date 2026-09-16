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
   * Migrate all libraries from network level back to blog level.
   */
  public function migrateToLocal() {
    $this->copyNetworkLibrariesToBlogs();
    $this->copyDatabaseTablesToBlogs();
    $this->deleteNetworkFilesDirectory();
    $this->dropNetworkTables();
  }

  /**
   * Copy network-level database tables to every blog.
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
      $wpdb->query(
        "TRUNCATE TABLE " . H5PCommons::build_full_db_table_name_singlesite('h5p_libraries_cachedassets')
      );
    });
  }

  /**
   * Create blog-level table from network-level source and copy its rows, preserving ids.
   *
   * @param string $blog_table_name    Destination blog-level table name.
   * @param string $network_table_name Source network-level table name.
   */
  protected function copyTableFromNetwork($blog_table_name, $network_table_name) {
    global $wpdb;

    if (!$this->createTableFromExisting($network_table_name, $blog_table_name)) {
      return;
    }

    $columns = $wpdb->get_col("DESCRIBE `{$blog_table_name}`", 0);
    $column_list = implode(', ', $columns);

    $wpdb->query(
      "INSERT INTO `{$blog_table_name}` ({$column_list}) SELECT {$column_list} FROM `{$network_table_name}`"
    );
  }

  /**
   * Copy network-level library files into every blog's libraries directory.
   */
  protected function copyNetworkLibrariesToBlogs() {
    $network_libraries_path = $this->getNetworkLibrariesPath();
    if (!is_dir($network_libraries_path)) {
      return;
    }

    H5PCommons::for_each_blog(function () use ($network_libraries_path) {
      $upload_directory = wp_upload_dir();
      $target_dir = "{$upload_directory['basedir']}/h5p/libraries";

      if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
          return;
        }
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
          return;
        }
      }
    });
  }

  /**
   * Delete network-level files directory.
   */
  public function deleteNetworkFilesDirectory() {
    $network_lib_base = $this->getH5PNetworkPath();
    if (is_dir($network_lib_base)) {
      H5PCore::deleteFileTree($network_lib_base);
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
