<?php

/**
 * H5P_Network_Migrate_To_Local
 *
 * Handles migration of H5P libraries from network-level back to
 * blog (local) level. Removes network-level files and database entries.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Migrate_To_Local extends H5P_Network_Admin_Base {

  /**
   * Migrate all libraries from the network level back to local (blog) level.
   *
   * Convenience method that deletes the network-level files and database tables.
   */
  public function migrateToLocal() {
    $this->copyNetworkLibrariesToBlogs();
    $this->copyDatabaseTablesToBlogs();

    // TODO: Cachesassets
    // TODO: Ensure served from local blogs again

    $this->deleteNetworkFilesDirectory();
    $this->dropNetworkTables();
  }

  /**
   * Copy network-level database tables to each blog's local tables.
   */
  protected function copyDatabaseTablesToBlogs() {
    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);

      try {
        foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
          $this->copyTableFromNetwork(
            H5PCommons::build_full_db_table_name_singlesite($table_name),
            H5PCommons::build_full_db_table_name_multisite($table_name)
          );
        }
      }
      finally {
        restore_current_blog();
      }
    }
  }

  /**
   * Create a blog-level table from a network-level source and copy its data.
   *
   * @param string $blog_table_name     Destination blog-level table name.
   * @param string $network_table_name  Source network-level table name.
   */
  protected function copyTableFromNetwork($blog_table_name, $network_table_name) {
    global $wpdb;

    if (!$this->createTableFromExisting($network_table_name, $blog_table_name)) {
      return;
    }

    // Copy data from network table to blog table, preserving IDs.
    $columns = $wpdb->get_col("DESCRIBE `{$blog_table_name}`", 0);
    $column_list = implode(', ', $columns);

    $wpdb->query(
      "INSERT INTO `{$blog_table_name}` ({$column_list}) SELECT {$column_list} FROM `{$network_table_name}`"
    );
  }

  /**
   * Copy network-level library files to each blog's libraries directory.
   */
  protected function copyNetworkLibrariesToBlogs() {
    $network_libraries_path = $this->getNetworkLibrariesPath();
    if (!is_dir($network_libraries_path)) {
      return;
    }

    $upload_directory = wp_upload_dir();
    $sites = get_sites(array('fields' => 'ids'));

    foreach ($sites as $blog_id) {
      switch_to_blog($blog_id);
      try {
        $target_dir = $this->getLibrariesDirForBlogId($blog_id, $upload_directory['basedir']);

        if (!is_dir($target_dir)) {
          if (!mkdir($target_dir, 0755, true)) {
            continue;
          }
        }

        $this->copyDirectory($network_libraries_path, $target_dir);
      }
      finally {
        restore_current_blog();
      }
    }
  }

  /**
   * Delete the network-level files directory.
   */
  public function deleteNetworkFilesDirectory() {
    $network_lib_base = $this->getH5PNetworkPath();
    if (is_dir($network_lib_base)) {
      H5PCore::deleteFileTree($network_lib_base);
    }
  }

  /**
   * Drop the network-level database tables.
   *
   * @return void
   */
  public function dropNetworkTables() {
    global $wpdb;

    foreach (H5PCommons::NETWORK_DATABASE_TABLE_NAMES as $table_name) {
      $wpdb->query("DROP TABLE IF EXISTS " . H5PCommons::build_full_db_table_name_multisite($table_name));
    }
  }
}
