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
   * Seconds of work per batch request. Checked between blogs, so no blog is left half done.
   */
  const BATCH_TIMEOUT = 5;

  /**
   * Upper bound of blogs per batch; the time budget usually ends batches earlier.
   */
  const BATCH_BLOG_LIMIT = 200;

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
   * Run one batch of blog-visiting phase from state's offset until time budget is spent; advance after last blog.
   *
   * @param array    $state      Migration state with 'phase' and 'offset'.
   * @param callable $per_blog   Receives the blog id and the state by reference, in the blog's context.
   * @param string   $next_phase Phase to continue with after the last blog.
   * @return array Updated migration state.
   */
  protected function runBlogBatch($state, callable $per_blog, $next_phase) {
    $start = microtime(true);

    $state['offset'] = H5PCommons::for_each_blog_page(
      $state['offset'],
      static::BATCH_BLOG_LIMIT,
      function ($blog_id) use ($per_blog, &$state, $start) {
        $per_blog($blog_id, $state);

        // Stop once the budget is spent, leaving no blog half done.
        return (microtime(true) - $start) <= static::BATCH_TIMEOUT;
      }
    );

    if ($state['offset'] >= H5PCommons::count_blogs()) {
      $state['phase'] = $next_phase;
      $state['offset'] = 0;
    }

    return $state;
  }

  /**
   * Ensure a directory exists.
   *
   * @param string $path Directory path.
   * @throws Exception If the directory cannot be created.
   */
  protected function ensureDir($path) {
    if (is_dir($path) || mkdir($path, 0755, true)) {
      return;
    }

    throw new Exception(
      sprintf(
        /* translators: %s: directory path */
        __('Failed to create directory "%s".', 'h5p'),
        $path
      )
    );
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
   *
   * @throws Exception If a table cannot be dropped.
   */
  public function dropNetworkTables() {
    foreach (H5PCommons::get_table_names(H5PCommons::TABLE_SCOPE_LIBRARY, H5PCommons::TABLE_SCOPE_NETWORK_ONLY) as $table_name) {
      $table = H5PCommons::build_full_db_table_name_multisite($table_name);
      $this->query_or_throw(
        "DROP TABLE IF EXISTS {$table}",
        sprintf(
          /* translators: %s: network table name */
          __('Failed to drop table "%s".', 'h5p'),
          $table
        )
      );
    }
  }

  /**
   * Remove the network level data: the library files folder and the network tables.
   *
   * @throws Exception If a part cannot be removed.
   */
  public function removeNetworkData() {
    $this->deleteNetworkFilesDirectory();
    $this->dropNetworkTables();
  }

  /**
   * Create cached assets for all H5P content on all blogs, unless asset aggregation is disabled.
   *
   * Failure is only logged, as assets are created lazily on view; one batch budget, as it runs in finishing request.
   *
   * @param string $log_prefix Prefix of the error log entry.
   */
  public function createCachedAssetsUnlessDisabled($log_prefix) {
    if (defined('H5P_DISABLE_AGGREGATION') && H5P_DISABLE_AGGREGATION === true) {
      return;
    }

    try {
      $this->createCachedAssets(microtime(true) + static::BATCH_TIMEOUT);
    }
    catch (Exception $exception) {
      error_log($log_prefix . $exception->getMessage());
    }
  }

  /**
   * Create cached assets for all H5P content on all blogs.
   *
   * Must run after network mode flag matches target level, as storage and table names resolve at call time.
   * Idempotent (same path as viewing content); stops at deadline, rest is created on first view.
   *
   * @param float|null $deadline microtime(true) after which no further content is processed, or null for none.
   */
  public function createCachedAssets($deadline = null) {
    H5PCommons::for_each_blog_with_tables(array('h5p_contents'), function () use ($deadline) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name_singlesite('h5p_contents');
      $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
      $content_ids = $wpdb->get_col("SELECT id FROM {$table_contents}");

      foreach ($content_ids as $content_id) {
        if ($deadline !== null && microtime(true) > $deadline) {
          return H5PCommons::STOP_ITERATION;
        }

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
