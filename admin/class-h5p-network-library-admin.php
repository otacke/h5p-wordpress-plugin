<?php
/**
 * H5P Network Library Admin class
 *
 * Library management in network mode: libraries are shared network wide, content lives on each blog.
 * Only the content upgrade and the cache rebuild are reachable in network mode (the blog Libraries page is not
 * registered there); they fan out over every blog via H5PCommons::for_each_blog().
 *
 * @package H5P_Plugin_Admin
 */
class H5PNetworkLibraryAdmin extends H5PLibraryAdmin {

  /**
   * Upgrades script lives in the shared network folder.
   *
   * @since 1.18.0
   * @param string $suffix Path fragment beginning at the h5p folder, e.g. /libraries/H5P.X-1.0/upgrades.js.
   * @return array{path: string, url: string}
   */
  protected function get_upgrade_script_location($suffix) {
    return array(
      'path' => H5PCommons::get_h5p_network_path() . $suffix,
      'url' => H5PCommons::get_h5p_network_url() . $suffix,
    );
  }

  /**
   * AJAX processing for content upgrade script, across all blogs.
   *
   * Content ids collide between blogs, so the JS<->PHP protocol addresses content with composite
   * "blogId_contentId" keys.
   */
  public function ajax_upgrade_progress() {
    $prepared = $this->prepare_upgrade_progress();
    $library_id = $prepared['library_id'];
    $to_library = $prepared['to_library'];

    $out = new stdClass();
    $out->params = array();
    $out->token = wp_create_nonce('h5p_content_upgrade');

    $this->apply_upgraded_params_from_request($to_library);

    $skipped = $this->parse_skipped_from_request();
    $out->skipped = $skipped['keys'];
    $skip_by_blog = $skipped['by_blog'];

    $out->left = $this->count_and_fill_next_batch($out, $library_id, $skip_by_blog);

    header('Content-type: application/json');
    print json_encode($out);
    exit;
  }

  /**
   * Apply upgraded params posted by upgrade script, grouped per blog.
   *
   * @since 1.18.0
   * @param object $to_library Library that content is being upgraded to.
   */
  private function apply_upgraded_params_from_request($to_library) {
    $params = filter_input(INPUT_POST, 'params');
    if ($params === NULL) {
      return;
    }

    $by_blog = array();
    foreach (json_decode($params) as $key => $param) {
      $parts = $this->split_content_key($key);
      if ($parts === NULL) {
        continue; // Malformed key, nothing to apply.
      }

      $by_blog[$parts[0]][] = array(
        'id' => $parts[1],
        'upgraded' => json_decode($param),
      );
    }

    foreach ($by_blog as $blog_id => $items) {
      H5PCommons::in_blog($blog_id, function () use ($items, $to_library) {
        foreach ($items as $item) {
          $this->apply_upgraded_params($item['id'], $item['upgraded'], $to_library);
        }
      });
    }
  }

  /**
   * Parse skipped content posted by upgrade script.
   *
   * @since 1.18.0
   * @return array{keys: array, by_blog: array} Composite keys to echo back unchanged, and a map of
   *         blog id to the list of content ids to exclude, ready for H5PCommons::int_placeholders().
   */
  private function parse_skipped_from_request() {
    $skipped = filter_input(INPUT_POST, 'skipped');
    if ($skipped === NULL) {
      return array('keys' => array(), 'by_blog' => array());
    }

    $keys = json_decode($skipped);

    $by_blog = array();
    foreach ($keys as $key) {
      $parts = $this->split_content_key($key);
      if ($parts === NULL) {
        continue;
      }

      $by_blog[$parts[0]][] = $parts[1];
    }

    foreach ($by_blog as $blog_id => $ids) {
      $by_blog[$blog_id] = array_values(array_unique($ids));
    }

    return array('keys' => $keys, 'by_blog' => $by_blog);
  }

  /**
   * Count content that still needs upgrading across all blogs, and fill the next upgrade batch, in one walk.
   *
   * Each blog is visited once: its remaining content is counted, and fetched while the batch has room.
   *
   * @since 1.18.0
   * @param object $out Response being built; params are added under composite "blogId_contentId" keys.
   * @param int $library_id Id of library to upgrade content of.
   * @param array $skip_by_blog Map of blog id to list of content ids to exclude.
   * @return int Number of content items left to upgrade, the batch included.
   */
  private function count_and_fill_next_batch($out, $library_id, $skip_by_blog) {
    $left = 0;

    H5PCommons::for_each_blog_with_tables(array('h5p_contents'), function ($blog_id) use (&$left, $out, $library_id, $skip_by_blog) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : array();

      $sql = "SELECT COUNT(id) FROM {$table_contents} WHERE library_id = %d";
      $args = array($library_id);
      if (!empty($skip)) {
        $sql .= ' AND id NOT IN (' . H5PCommons::int_placeholders($skip) . ')';
        $args = array_merge($args, $skip);
      }
      $blog_left = (int) $wpdb->get_var($wpdb->prepare($sql, $args));
      $left += $blog_left;

      $remaining = self::UPGRADE_BATCH_SIZE - count($out->params);
      if ($blog_left === 0 || $remaining <= 0) {
        return; // Nothing to fetch here, or the batch is full; counting goes on.
      }

      $skip_query = empty($skip)
        ? ''
        : ' AND id NOT IN (' . $wpdb->prepare(H5PCommons::int_placeholders($skip), $skip) . ')';
      foreach ($this->get_next_contents($library_id, $skip_query, $remaining) as $content) {
        $out->params[$blog_id . '_' . $content->id] =
          '{"params":' . $content->params .
          ',"metadata":' . \H5PMetadata::toJSON($content) . '}';
      }
    });

    return $left;
  }


  /**
   * Refuse the blog level restriction toggle.
   *
   * In network mode, restrictions are set per blog on the network restrictions page, and the restricted column of
   * the shared libraries table is not used, so toggling it would silently do nothing.
   *
   * @since 1.18.0
   */
  public function ajax_restrict_access() {
    $this->require_manage_libraries();

    status_header(H5PCommons::HTTP_FORBIDDEN);
    print __('Restrictions are managed on the H5P Network Restrictions page.', $this->plugin_slug);
    exit;
  }

  /**
   * Help rebuild all content caches, across all blogs.
   */
  public function ajax_rebuild_cache() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      exit; // POST is required
    }

    $this->require_manage_libraries();

    $left = (new H5P_Network_Content_Cache())->rebuild(microtime(TRUE) + self::UPGRADE_BATCH_TIMEOUT);

    print $left;
    exit;
  }

  /**
   * Split a composite "blogId_contentId" key into its parts.
   *
   * @since 1.18.0
   * @param string $key Composite content key from upgrade script.
   * @return array|null [blog id, content id], or NULL if key is malformed.
   */
  private function split_content_key($key) {
    $parts = explode('_', (string) $key, 2);

    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
      return NULL;
    }

    return array((int) $parts[0], (int) $parts[1]);
  }
}
