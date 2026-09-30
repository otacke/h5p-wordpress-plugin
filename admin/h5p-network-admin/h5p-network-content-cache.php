<?php

/**
 * H5P_Network_Content_Cache
 *
 * Rebuilds the content caches (filtered parameters) of all blogs in network mode, one time budget at a time.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Content_Cache {

  /**
   * Rebuild as many content caches as fit in the time budget, so a big network takes several requests.
   *
   * Works like the "Rebuild cache" button of the Libraries page. Its endpoint, wp_ajax_h5p_rebuild_cache
   * (H5PNetworkLibraryAdmin::ajax_rebuild_cache()), is not reused: it checks no nonce, and it fetches H5PCore
   * once before looping over the blogs, so filterParameters() deletes and creates the export files in the
   * folders of the main blog instead of the content's blog. get_h5p_instance() keys its instances by the
   * current blog, so H5PCore is fetched per blog here.
   *
   * @param float $deadline microtime(TRUE) after which no new content is started.
   *
   * @return int Number of contents on all blogs whose cache is still missing.
   */
  public function rebuild($deadline) {
    $left = 0;
    H5PCommons::for_each_blog(function () use (&$left) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      if (!H5P_Network_Library_Helpers::table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $left += (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table_contents} WHERE filtered = ''");
    });

    H5PCommons::for_each_blog(function () use (&$left, $deadline) {
      global $wpdb;

      if ($left <= 0) {
        return;
      }

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      if (!H5P_Network_Library_Helpers::table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $contents = $wpdb->get_results("SELECT id FROM {$table_contents} WHERE filtered = ''");
      if (empty($contents)) {
        return;
      }

      $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
      foreach ($contents as $content) {
        if (microtime(TRUE) > $deadline || $left <= 0) {
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
