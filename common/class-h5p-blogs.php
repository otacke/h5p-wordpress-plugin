<?php
/**
 * H5P_Blogs
 *
 * Iteration over blogs of a multisite network.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Blogs {

  /** Value a for_each_blog() callback returns to stop iteration over the remaining blogs. */
  const STOP_ITERATION = 'h5p_stop_iteration';

  /**
   * Get ids of all blogs in network.
   *
   * 'number' => 0 is required: get_sites() defaults to 100 and would silently truncate larger networks.
   *
   * @since 1.18.0
   * @return int[] Blog ids, ordered by id.
   */
  public static function get_all_blog_ids() {
    return get_sites(
      array(
        'fields'  => 'ids',
        'number'  => 0,
        'orderby' => 'id',
        'order'   => 'ASC',
      )
    );
  }

  /**
   * Get number of blogs in network.
   *
   * @since 1.18.0
   * @return int Number of blogs.
   */
  public static function count_blogs() {
    if (!is_multisite()) {
      return 1;
    }

    return count(self::get_all_blog_ids());
  }

  /**
   * Get ids of one page of blogs in network, ordered by id so paging by offset stays stable.
   *
   * @since 1.18.0
   * @param int $offset Number of blogs to skip.
   * @param int $number Maximum number of blogs to return.
   * @return int[] Blog ids, ordered by id.
   */
  public static function get_blog_ids_page($offset, $number) {
    return get_sites(
      array(
        'fields'  => 'ids',
        'number'  => (int) $number,
        'offset'  => (int) $offset,
        'orderby' => 'id',
        'order'   => 'ASC',
      )
    );
  }

  /**
   * Run callback in context of one blog.
   *
   * @since 1.18.0
   * @param int      $blog_id Blog to switch to for the duration of the callback.
   * @param callable $callback
   * @return mixed Result of the callback.
   */
  public static function in_blog($blog_id, callable $callback) {
    switch_to_blog($blog_id);

    try {
      return $callback();
    }
    finally {
      restore_current_blog();
    }
  }

  /**
   * Run callback once per blog.
   *
   * @since 1.18.0
   * @param callable $callback Receives current blog id. Returning self::STOP_ITERATION stops.
   */
  public static function for_each_blog(callable $callback) {
    if (!is_multisite()) {
      $callback(get_current_blog_id());
      return;
    }

    self::switch_through_blogs(self::get_all_blog_ids(), $callback, 0, self::STOP_ITERATION);
  }

  /**
   * Run callback once per blog that has all given H5P tables, i.e. uses H5P; other blogs are skipped.
   *
   * @since 1.18.0
   * @param string[] $table_names Table names without prefix, resolved per blog (e.g. 'h5p_contents').
   * @param callable $callback    Receives current blog id. Returning self::STOP_ITERATION stops.
   */
  public static function for_each_blog_with_tables(array $table_names, callable $callback) {
    self::for_each_blog(function ($blog_id) use ($table_names, $callback) {
      foreach ($table_names as $table_name) {
        if (!H5P_Tables::table_exists(H5P_Tables::build_full_db_table_name_singlesite($table_name))) {
          return null;
        }
      }

      return $callback($blog_id);
    });
  }

  /**
   * Run callback once per blog of one page of blogs, so long operations can span several requests.
   *
   * @since 1.18.0
   * @param int      $offset   Number of blogs to skip.
   * @param int      $number   Maximum number of blogs to visit.
   * @param callable $callback Receives current blog id and its offset. Returning false stops.
   * @return int Offset after last visited blog.
   */
  public static function for_each_blog_page($offset, $number, callable $callback) {
    $offset = (int) $offset;

    if (!is_multisite()) {
      if ($offset === 0 && $number > 0) {
        $callback(get_current_blog_id(), $offset);
        return 1;
      }

      return $offset;
    }

    return self::switch_through_blogs(
      self::get_blog_ids_page($offset, $number),
      $callback,
      $offset
    );
  }

  /**
   * Run callback for each given blog, in blog context.
   *
   * @since 1.18.0
   * @param int[]    $blog_ids   Blog ids to visit.
   * @param callable $callback   Receives current blog id and its offset. Returning $stop_value stops.
   * @param int      $offset     Offset of first blog in list.
   * @param mixed    $stop_value Callback result that stops iteration.
   * @return int Offset after last visited blog.
   */
  private static function switch_through_blogs($blog_ids, callable $callback, $offset = 0, $stop_value = false) {
    foreach ($blog_ids as $blog_id) {
      switch_to_blog($blog_id);

      try {
        $result = $callback((int) $blog_id, $offset);
      }
      finally {
        restore_current_blog();
      }

      $offset++;

      if ($result === $stop_value) {
        break;
      }
    }

    return $offset;
  }
}
