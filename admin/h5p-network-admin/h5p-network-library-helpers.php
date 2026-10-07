<?php

/**
 * H5P_Network_Library_Helpers
 *
 * Helpers shared by the network level library management classes and their views: content counts,
 * version comparison and formatting, and H5P core messages.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Library_Helpers {

  /**
   * Count the contents that use each library version as their main library, across all blogs.
   *
   * @return array Map of library id to content count.
   */
  public static function get_content_counts() {
    $counts = array();

    H5PCommons::for_each_blog(function () use (&$counts) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!H5PCommons::table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet.
      }

      $rows = $wpdb->get_results(
        "SELECT library_id, COUNT(id) AS content_count
          FROM {$table_contents}
          GROUP BY library_id"
      );

      foreach ($rows as $row) {
        $id = (int) $row->library_id;
        $counts[$id] = (isset($counts[$id]) ? $counts[$id] : 0) + (int) $row->content_count;
      }
    });

    return $counts;
  }

  /**
   * Build the 'messages' shape of the installer results.
   *
   * @param array $info List of info messages.
   * @param array $error List of error messages.
   *
   * @return array
   */
  public static function messages($info = array(), $error = array()) {
    return array('info' => $info, 'error' => $error);
  }

  /**
   * Compare two library versions.
   *
   * @param object $a
   * @param object $b
   *
   * @return int
   */
  public static function compare_library_versions($a, $b) {
    foreach (array('major_version', 'minor_version', 'patch_version') as $component) {
      $a_value = (int) $a->{$component};
      $b_value = (int) $b->{$component};
      if ($a_value !== $b_value) {
        return $a_value < $b_value ? -1 : 1;
      }
    }
    return 0;
  }

  /**
   * Format the version of a library row as major.minor.patch.
   *
   * @param array $library
   *
   * @return string
   */
  public static function format_library_version($library) {
    return $library['majorVersion'] . '.' . $library['minorVersion'] . '.' . $library['patchVersion'];
  }

  /**
   * Format the name of a library row as title (machine name).
   *
   * @param array $library
   *
   * @return string
   */
  public static function format_library_name($library) {
    return sprintf('%s (%s)', $library['title'], $library['machineName']);
  }

  /**
   * Take the messages that H5P core has set during this request.
   *
   * @return array Lists of 'info' and 'error' messages as plain text, as the page shows them as text.
   */
  public static function collect_h5p_messages() {
    $interface = H5P_Plugin::get_instance()->get_h5p_instance('interface');

    $messages = array('info' => array(), 'error' => array());
    foreach (array_keys($messages) as $type) {
      foreach ((array) $interface->getMessages($type) as $message) {
        // Error messages are objects with a code, info messages are strings.
        $messages[$type][] = wp_strip_all_tags(is_object($message) ? $message->message : $message);
      }
    }

    return $messages;
  }
}
