<?php

/**
 * H5P_Network_Hub_Cache
 *
 * The content type cache of the H5P Hub: its rows, and the newest version of each content type.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Hub_Cache {

  /**
   * Fetch the rows of the content type cache of the H5P Hub.
   *
   * @return array
   */
  public static function get_rows() {
    return (array) (new H5PEditorWordPressAjax())->getContentTypeCache();
  }

  /**
   * Reduce the cache to the newest version of each content type.
   *
   * @return array Map of machine name to the newest row of that content type.
   */
  public static function newest_by_name() {
    $newest = array();
    foreach (self::get_rows() as $row) {
      if (!isset($newest[$row->machine_name])
        || H5P_Network_Library_Helpers::compare_library_versions($row, $newest[$row->machine_name]) > 0) {
        $newest[$row->machine_name] = $row;
      }
    }

    return $newest;
  }
}
