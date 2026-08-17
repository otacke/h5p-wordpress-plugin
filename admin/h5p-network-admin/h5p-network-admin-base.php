<?php

/**
 * H5P_Network_Admin_Base
 *
 * Shared base class for network migration operations. Provides common
 * helpers for path resolution, table name resolution, and AJAX nonce
 * verification.
 * @package H5P
 * @since 1.19.0
 */
abstract class H5P_Network_Admin_Base {
  use H5PUtils;

  /**
   * Get the network-level libraries directory path.
   *
   * @return string
   */
  protected function getH5PNetworkPath() {
    return H5PCommons::get_h5p_network_path();
  }

  /**
   * Get the network-level libraries directory path.
   *
   * @return string
   */
  protected function getNetworkLibrariesPath() {
    return  $this->getH5PNetworkPath() . '/libraries';
  }

  /**
   * Get the network-level cachedassets directory path.
   *
   * @return string
   */
  protected function getNetworkCachedassetsPath() {
    return  $this->getH5PNetworkPath() . '/cachedassets';
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
   * Create a table by copying the schema from an existing table.
   *
   * Uses SHOW CREATE TABLE, renames the table, strips AUTO_INCREMENT,
   * then executes the resulting statement.
   *
   * @param string $source_table_name  Source table name.
   * @param string $new_table_name  Destination table name.
   * @param bool   $fail_on_error  Whether to throw on failure (default false).
   * @return bool True on success, false on failure (when fail_on_error is false).
   */
  protected function createTableFromExisting($source_table_name, $new_table_name, $fail_on_error = false) {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $row = $wpdb->get_row(
      "SHOW CREATE TABLE `{$source_table_name}`",
      ARRAY_N
    );

    if ($row === null || count($row) < 2) {
      if ($fail_on_error) {
        throw new Exception(
          "Failed to retrieve schema for source table: {$source_table_name}"
        );
      }
      return false;
    }

    $create_statement = $row[1];
    $create_statement = str_replace(
      "CREATE TABLE `{$source_table_name}`",
      "CREATE TABLE `{$new_table_name}`",
      $create_statement
    );

    // Strip AUTO_INCREMENT so new table starts from 1.
    $create_statement = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $create_statement);

    return $wpdb->query($create_statement) !== false;
  }

  /**
   * Verify the AJAX request nonce.
   *
   * @throws Exception If the nonce is invalid.
   */
  protected function verifyNetworkNonce() {
    check_ajax_referer('h5p_network_ajax', 'nonce', true);
  }
}
