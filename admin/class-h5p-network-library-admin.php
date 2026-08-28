<?php
/**
 * H5P Network Library Admin class
 *
 * Handles library management in network mode, where libraries are shared across whole
 * network but content lives on each individual blog. Overrides only seams that touch
 * per-blog content tables, fanning out over all blogs via H5PCommons::for_each_blog().
 *
 * @package H5P_Plugin_Admin
 */
class H5PNetworkLibraryAdmin extends H5PLibraryAdmin {

  /**
   * Content count per library across all blogs.
   *
   * Gathered lazily once per request, because the library admin list asks about every
   * library in turn and switching blogs is not cheap.
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

      if (!$this->table_exists($table_contents_libraries) || !$this->table_exists($table_contents)) {
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
   * Counts for all libraries are gathered in one query per blog and kept for the
   * rest of the request, because the library admin list asks about every library
   * in turn and switching blogs is not cheap.
   *
   * Skipped list is accepted for signature compatibility but ignored here.
   * Network implementation of ajax_upgrade_progress() applies skips per blog instead.
   *
   * @since 1.19.0
   * @param int $library_id Id of library to count content for.
   * @param string|null $skipped Comma separated content ids to exclude (cannot be handled in network mode), or NULL.
   * @return int
   */
  protected function get_num_content_using_library($library_id, $skipped = NULL) {
    if ($this->library_content_counts === NULL) {
      $this->library_content_counts = array();

      H5PCommons::for_each_blog(function () {
        global $wpdb;

        $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

        if (!$this->table_exists($table_contents)) {
          return; // Blog has no H5P content tables yet.
        }

        $rows = $wpdb->get_results(
          "SELECT library_id, COUNT(id) AS content_count
            FROM {$table_contents}
            GROUP BY library_id"
        );

        foreach ($rows as $row) {
          $id = (int) $row->library_id;
          $this->library_content_counts[$id] =
            (isset($this->library_content_counts[$id]) ? $this->library_content_counts[$id] : 0)
            + (int) $row->content_count;
        }
      });
    }

    return isset($this->library_content_counts[(int) $library_id])
      ? $this->library_content_counts[(int) $library_id]
      : 0;
  }

  /**
   * AJAX processing for content upgrade script, across all blogs.
   *
   * Content ids collide between blogs, so JS<->PHP protocol addresses content with composite
   * "blogId_contentId" keys. Upgrades are applied per blog, and next batch is filled from every blog until
   * it reaches UPGRADE_BATCH_SIZE entries.
   */
  public function ajax_upgrade_progress() {
    $prepared = $this->prepare_upgrade_progress();
    $library_id = $prepared['library_id'];
    $to_library = $prepared['to_library'];

    // Prepare response
    $out = new stdClass();
    $out->params = array();
    $out->token = wp_create_nonce('h5p_content_upgrade');

    // Apply upgraded params posted by script, per blog.
    $this->apply_upgraded_params_from_request($to_library);

    // Determine if any content has been skipped during process
    $skipped = $this->parse_skipped_from_request();
    $out->skipped = $skipped['keys'];
    $skip_by_blog = $skipped['by_blog'];

    // Fill next batch of remaining content from every blog.
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
      switch_to_blog($blog_id);

      try {
        foreach ($items as $item) {
          $this->apply_upgraded_params($item['id'], $item['upgraded'], $to_library);
        }
      }
      finally {
        restore_current_blog();
      }
    }
  }

  /**
   * Parse skipped content posted by upgrade script.
   *
   * @since 1.19.0
   * @return array{keys: array, by_blog: array} Composite keys to echo back unchanged, and a map of
   *         blog id to comma separated content ids for queries.
   */
  private function parse_skipped_from_request() {
    $skipped = filter_input(INPUT_POST, 'skipped');
    if ($skipped === NULL) {
      return array('keys' => array(), 'by_blog' => array());
    }

    // Keep composite keys as-is, so script can echo them back unchanged.
    $keys = json_decode($skipped);

    // Split into per-blog lists of plain content ids for queries.
    $by_blog = array();
    foreach ($keys as $key) {
      $parts = $this->split_content_key($key);
      if ($parts === NULL) {
        continue;
      }

      $by_blog[$parts[0]][] = $parts[1];
    }

    foreach ($by_blog as $blog_id => $ids) {
      $by_blog[$blog_id] = implode(',', array_unique($ids));
    }

    return array('keys' => $keys, 'by_blog' => $by_blog);
  }

  /**
   * Count content that still needs upgrading, across all blogs.
   *
   * @since 1.19.0
   * @param int $library_id Id of library to count content for.
   * @param array $skip_by_blog Map of blog id to comma separated content ids to exclude.
   * @return int
   */
  private function count_remaining_content($library_id, $skip_by_blog) {
    $left = 0;

    H5PCommons::for_each_blog(function ($blog_id) use (&$left, $library_id, $skip_by_blog) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet
      }

      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : '';
      $skip_query = empty($skip) ? '' : " AND id NOT IN ($skip)";
      $left += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$table_contents} WHERE library_id = %d {$skip_query}",
        $library_id
      ));
    });

    return $left;
  }

  /**
   * Fill next upgrade batch from every blog.
   *
   * @since 1.19.0
   * @param object $out Response being built; params are added under composite "blogId_contentId" keys.
   * @param int $library_id Id of library to fetch content for.
   * @param array $skip_by_blog Map of blog id to comma separated content ids to exclude.
   */
  private function fill_next_batch($out, $library_id, $skip_by_blog) {
    H5PCommons::for_each_blog(function ($blog_id) use (&$out, $library_id, $skip_by_blog) {
      $remaining = self::UPGRADE_BATCH_SIZE - count($out->params);
      if ($remaining <= 0) {
        return; // Batch is full.
      }

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to fetch.
      }

      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : '';
      $contents = $this->get_next_contents($library_id, $skip === '' ? '' : " AND id NOT IN ($skip)", $remaining);
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

    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');

    // Do as many as we can in five seconds.
    $start = microtime(TRUE);

    // Count how much work is left, across all blogs.
    $left = 0;
    H5PCommons::for_each_blog(function ($blog_id) use (&$left) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $left += (int) $wpdb->get_var(
        "SELECT COUNT(id) FROM {$table_contents} WHERE filtered = ''"
      );
    });

    // Rebuild as many caches as fit in time budget.
    H5PCommons::for_each_blog(function ($blog_id) use (&$left, $core, $start) {
      global $wpdb;

      if ($left <= 0) {
        return; // Nothing left to do.
      }

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $contents = $wpdb->get_results(
        "SELECT id FROM {$table_contents} WHERE filtered = ''"
      );

      foreach ($contents as $content) {
        if ((microtime(TRUE) - $start) > self::UPGRADE_BATCH_TIMEOUT || $left <= 0) {
          break;
        }

        $content = $core->loadContent($content->id);
        $core->filterParameters($content);
        $left--;
      }
    });

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

  /**
   * Determine whether given database table exists on current blog.
   *
   * @since 1.19.0
   * @param string $table Full table name.
   * @return bool
   */
  private function table_exists($table) {
    global $wpdb;

    return $wpdb->get_var(
      $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    ) === $table;
  }
}
