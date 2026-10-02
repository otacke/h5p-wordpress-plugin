<?php
/**
 * H5P Network Library Admin class
 *
 * Library management in network mode: libraries are shared network wide, content lives on each blog.
 * Overrides only the seams that touch per-blog content tables, fanning out via H5PCommons::for_each_blog().
 *
 * @package H5P_Plugin_Admin
 */
class H5PNetworkLibraryAdmin extends H5PLibraryAdmin {

  /**
   * Content count per library across all blogs.
   *
   * @var array|null Keyed by library id.
   */
  private $library_content_counts = NULL;

  /**
   * List content that uses given library, across all blogs.
   *
   * @since 1.19.0
   * @param object $library Library to list content for.
   * @return array Rows with id, title and blog_id.
   */
  protected function get_contents_using_library($library) {
    $contents = array();

    H5PCommons::for_each_blog(function ($blog_id) use (&$contents, $library) {
      global $wpdb;

      $table_contents_libraries = H5PCommons::build_full_db_table_name('h5p_contents_libraries');
      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!H5PCommons::table_exists($table_contents_libraries) || !H5PCommons::table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet.
      }

      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT hc.id, hc.title
          FROM {$table_contents_libraries} hcl
          JOIN {$table_contents} hc ON hcl.content_id = hc.id
          WHERE hcl.library_id = %d
          ORDER BY hc.title",
        $library->id
      ));

      foreach ($rows as $row) {
        $row->blog_id = $blog_id;
        $contents[] = $row;
      }
    });

    return $contents;
  }

  /**
   * Count content that uses given library, across all blogs.
   *
   * Counted once per request for every library at once, because the library admin list asks about each in
   * turn and switching blogs is not cheap. $skipped is ignored: ajax_upgrade_progress() skips per blog.
   *
   * @since 1.19.0
   * @param int $library_id Id of library to count content for.
   * @param string|null $skipped Comma separated content ids to exclude (cannot be handled in network mode), or NULL.
   * @return int
   */
  protected function get_num_content_using_library($library_id, $skipped = NULL) {
    if ($this->library_content_counts === NULL) {
      $this->library_content_counts = H5P_Network_Library_Helpers::get_content_counts();
    }

    return isset($this->library_content_counts[(int) $library_id])
      ? $this->library_content_counts[(int) $library_id]
      : 0;
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

    $left = $this->count_remaining_content($library_id, $skip_by_blog);
    if ($left > 0) {
      $this->fill_next_batch($out, $library_id, $skip_by_blog);
    }

    $out->left = $left;

    header('Content-type: application/json');
    print json_encode($out);
    exit;
  }

  /**
   * Apply upgraded params posted by upgrade script, grouped per blog.
   *
   * @since 1.19.0
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
   * @since 1.19.0
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
   * Count content that still needs upgrading, across all blogs.
   *
   * @since 1.19.0
   * @param int $library_id Id of library to count content for.
   * @param array $skip_by_blog Map of blog id to list of content ids to exclude.
   * @return int
   */
  private function count_remaining_content($library_id, $skip_by_blog) {
    $left = 0;

    H5PCommons::for_each_blog(function ($blog_id) use (&$left, $library_id, $skip_by_blog) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!H5PCommons::table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet
      }

      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : array();
      $sql = "SELECT COUNT(id) FROM {$table_contents} WHERE library_id = %d";
      $args = array($library_id);
      if (!empty($skip)) {
        $sql .= ' AND id NOT IN (' . H5PCommons::int_placeholders($skip) . ')';
        $args = array_merge($args, $skip);
      }

      $left += (int) $wpdb->get_var($wpdb->prepare($sql, $args));
    });

    return $left;
  }

  /**
   * Fill next upgrade batch from every blog.
   *
   * @since 1.19.0
   * @param object $out Response being built; params are added under composite "blogId_contentId" keys.
   * @param int $library_id Id of library to fetch content for.
   * @param array $skip_by_blog Map of blog id to list of content ids to exclude.
   */
  private function fill_next_batch($out, $library_id, $skip_by_blog) {
    H5PCommons::for_each_blog(function ($blog_id) use (&$out, $library_id, $skip_by_blog) {
      global $wpdb;

      $remaining = self::UPGRADE_BATCH_SIZE - count($out->params);
      if ($remaining <= 0) {
        return H5PCommons::STOP_ITERATION; // Batch is full.
      }

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!H5PCommons::table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to fetch.
      }

      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : array();
      $skip_query = empty($skip)
        ? ''
        : ' AND id NOT IN (' . $wpdb->prepare(H5PCommons::int_placeholders($skip), $skip) . ')';
      $contents = $this->get_next_contents($library_id, $skip_query, $remaining);
      foreach ($contents as $content) {
        $out->params[$blog_id . '_' . $content->id] =
          '{"params":' . $content->params .
          ',"metadata":' . \H5PMetadata::toJSON($content) . '}';
      }
    });
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
   * @since 1.19.0
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
