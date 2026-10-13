<?php

/**
 * H5P_Network_Content_Cache
 *
 * Rebuilds the content caches (filtered parameters) of all blogs in network mode, one time budget at a time.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Content_Cache {

  /**
   * Rebuild as many content caches as fit in the time budget, so a big network takes several requests.
   *
   * Works like the "Rebuild cache" button of the Libraries page; H5PNetworkLibraryAdmin::ajax_rebuild_cache()
   * delegates here. get_h5p_instance() keys its instances by the current blog, so H5PCore is fetched per
   * blog: filterParameters() must delete and create the export files in the folders of the content's own
   * blog, not the main blog's.
   *
   * @param float $deadline microtime(TRUE) after which no new content is started.
   *
   * @return int Number of contents on all blogs whose cache is still missing.
   */
  public function rebuild($deadline) {
    // One walk: every blog's missing caches are counted, and rebuilt while time is left.
    $left = 0;
    H5PCommons::for_each_blog_with_tables(array('h5p_contents'), function () use (&$left, $deadline) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      $contents = $wpdb->get_results("SELECT id FROM {$table_contents} WHERE filtered = ''");
      $left += count($contents);

      if (empty($contents) || microtime(TRUE) > $deadline) {
        return;
      }

      $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
      foreach ($contents as $content) {
        if (microtime(TRUE) > $deadline) {
          break;
        }

        $content = $core->loadContent($content->id);
        $core->filterParameters($content);
        $left--;
      }
    });

    return $left;
  }
}
