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
    // Move files to blogs based on contents -> library -> libraries_libraries
    // Re-create libraries, libraries_cachedassets, libraries_languages, libraries_libraries
    // CachedAssets!!
    // Ensure all served from blogs again
    $this->deleteNetworkLibrariesDirectory();
    $this->dropNetworkTables();
  }

  /**
   * Delete the network-level libraries directory.
   */
  public function deleteNetworkLibrariesDirectory() {
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

    $network_table_libraries = $this->getNetworkLibrariesTableName();
    $wpdb->query("DROP TABLE IF EXISTS {$network_table_libraries}");

    $network_table_libraries_cachedassets = $this->getNetworkLibrariesCachedassetsTableName();
    $wpdb->query("DROP TABLE IF EXISTS {$network_table_libraries_cachedassets}");

    $network_table_libraries_languages = $this->getNetworkLibrariesLanguagesTableName();
    $wpdb->query("DROP TABLE IF EXISTS {$network_table_libraries_languages}");

    $network_table_libraries_libraries = $this->getNetworkLibrariesLibrariesTableName();
    $wpdb->query("DROP TABLE IF EXISTS {$network_table_libraries_libraries}");
  }
}
