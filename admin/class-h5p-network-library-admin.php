<?php
/**
 * H5P Network Library Admin class
 *
 * Handles library management in network mode, where libraries are shared across the whole
 * network but content lives on each individual blog. Overrides only seams that touch
 * per-blog content tables, fanning out over all blogs via H5PCommons::for_each_blog().
 *
 * @package H5P_Plugin_Admin
 */
class H5PNetworkLibraryAdmin extends H5PLibraryAdmin {

  /**
   * List content that uses the given library, across all blogs.
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
   * Skipped list is accepted for signature compatibility but ignored here; the network
   * implementation of ajax_upgrade_progress() applies skips per blog instead.
   *
   * @since 1.19.0
   * @param int $library_id Id of library to count content for.
   * @param string|null $skipped Comma separated content ids to exclude, or NULL.
   * @return int
   */
  protected function get_num_content_using_library($library_id, $skipped = NULL) {
    $count = 0;

    H5PCommons::for_each_blog(function ($blog_id) use (&$count, $library_id) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet.
      }

      $count += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$table_contents} WHERE library_id = %d",
        $library_id
      ));
    });

    return $count;
  }

  /**
   * AJAX processing for content upgrade script, across all blogs.
   *
   * Content ids collide between blogs, so the JS<->PHP protocol addresses content with composite
   * "blogId_contentId" keys. Upgrades are applied per blog, and next batch is filled from every blog until
   * it reaches 40 entries.
   */
  public function ajax_upgrade_progress() {
    $prepared = $this->prepare_upgrade_progress();
    $library_id = $prepared['library_id'];
    $to_library = $prepared['to_library'];

    // Prepare response
    $out = new stdClass();
    $out->params = array();
    $out->token = wp_create_nonce('h5p_content_upgrade');

    // Get updated params, grouped by blog
    $params = filter_input(INPUT_POST, 'params');
    if ($params !== NULL) {
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

    // Determine if any content has been skipped during the process
    $skipped = filter_input(INPUT_POST, 'skipped');
    if ($skipped !== NULL) {
      // Keep the composite keys as-is, so the script can echo them back unchanged.
      $out->skipped = json_decode($skipped);

      // Split into per-blog lists of plain content ids for the queries below.
      $skip_by_blog = array();
      foreach ($out->skipped as $key) {
        $parts = $this->split_content_key($key);
        if ($parts === NULL) {
          continue;
        }

        $skip_by_blog[$parts[0]][] = $parts[1];
      }

      foreach ($skip_by_blog as $blog_id => $ids) {
        $skip_by_blog[$blog_id] = implode(',', array_unique($ids));
      }
    }
    else {
      $out->skipped = array();
      $skip_by_blog = array();
    }

    // Count the remaining content across all blogs.
    $left = 0;
    H5PCommons::for_each_blog(function ($blog_id) use (&$left, $library_id, $skip_by_blog) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to count.
      }

      $skip = isset($skip_by_blog[$blog_id]) ? $skip_by_blog[$blog_id] : '';
      $skip_query = empty($skip) ? '' : " AND id NOT IN ($skip)";
      $left += (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(id) FROM {$table_contents} WHERE library_id = %d {$skip_query}",
        $library_id
      ));
    });

    if ($left > 0) {
      // Fill the next batch from every blog.
      H5PCommons::for_each_blog(function ($blog_id) use (&$out, $library_id, $skip_by_blog) {
        global $wpdb;

        $remaining = 40 - count($out->params);
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

    $out->left = $left;

    header('Content-type: application/json');
    print json_encode($out);
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

    // Rebuild as many caches as fit in the time budget.
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
        if ((microtime(TRUE) - $start) > 5 || $left <= 0) {
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
   * @param string $key Composite content key from the upgrade script.
   * @return array|null [blog id, content id], or NULL if the key is malformed.
   */
  private function split_content_key($key) {
    $parts = explode('_', (string) $key, 2);

    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
      return NULL;
    }

    return array((int) $parts[0], (int) $parts[1]);
  }

  /**
   * Whether the given database table exists on the current blog.
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
