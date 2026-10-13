<?php

/**
 * H5P_Network_Admin_Base
 *
 * Shared base for network migration operations: path resolution, table creation.
 * @package H5P
 * @since 1.18.0
 */
abstract class H5P_Network_Admin_Base {
  use H5PUtils;

  /**
   * Get network-level H5P base directory path.
   *
   * @return string
   */
  protected function getH5PNetworkPath() {
    return H5PCommons::get_h5p_network_path();
  }

  /**
   * Get network-level libraries directory path.
   *
   * @return string
   */
  protected function getNetworkLibrariesPath() {
    return  $this->getH5PNetworkPath() . '/libraries';
  }

  /**
   * Get network-level cachedassets directory path.
   *
   * @return string
   */
  protected function getNetworkCachedassetsPath() {
    return  $this->getH5PNetworkPath() . '/cachedassets';
  }

  /**
   * Create cached assets for all H5P content on all blogs.
   *
   * Must run after network mode flag matches target level, since storage and table names resolve at call
   * time. Idempotent: uses same code path as viewing content, so existing cached assets stay untouched.
   */
  public function createCachedAssets() {
    H5PCommons::for_each_blog(function () {
      global $wpdb;

      $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
      $table_contents = H5PCommons::build_full_db_table_name_singlesite('h5p_contents');
      $content_ids = $wpdb->get_col("SELECT id FROM {$table_contents}");

      foreach ($content_ids as $content_id) {
        $dependencies = $core->loadContentDependencies($content_id, 'preloaded');
        $core->getDependenciesFiles($dependencies);
      }
    });
  }

  /**
   * Run a query, throwing if the database reports an error.
   *
   * @param string $query   SQL, prepared where it holds values.
   * @param string $message Translated message of the exception.
   * @return int|bool What $wpdb->query() returned.
   * @throws Exception If the query fails.
   */
  protected function query_or_throw($query, $message) {
    global $wpdb;

    $result = $wpdb->query($query);
    if ($result === false) {
      throw new Exception($message);
    }

    return $result;
  }

  /**
   * Create table by copying schema from existing table. Drops destination first.
   *
   * @param string $source_table_name Source table name.
   * @param string $new_table_name    Destination table name.
   * @throws Exception If the source schema cannot be read or the table cannot be created.
   */
  protected function createTableFromExisting($source_table_name, $new_table_name) {
    global $wpdb;

    $row = $wpdb->get_row(
      "SHOW CREATE TABLE `{$source_table_name}`",
      ARRAY_N
    );

    if ($row === null || count($row) < 2) {
      throw new Exception(
        sprintf(
          /* translators: %s: table name */
          __('Failed to read the schema of table "%s".', 'h5p'),
          $source_table_name
        )
      );
    }

    $create_statement = $row[1];
    $create_statement = str_replace(
      "CREATE TABLE `{$source_table_name}`",
      "CREATE TABLE `{$new_table_name}`",
      $create_statement
    );

    // Strip AUTO_INCREMENT so new table starts from 1.
    $create_statement = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $create_statement);

    $wpdb->query("DROP TABLE IF EXISTS `{$new_table_name}`");

    $this->query_or_throw(
      $create_statement,
      sprintf(
        /* translators: %s: table name */
        __('Failed to create table "%s".', 'h5p'),
        $new_table_name
      )
    );
  }
}
