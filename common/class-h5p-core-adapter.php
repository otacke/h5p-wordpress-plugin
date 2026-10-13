<?php
/**
 * H5P_Core_Adapter
 *
 * All plugin reliance on H5P core internals beyond its interfaces; the one class to adapt when core updates.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Core_Adapter {

  /**
   * Download content type from H5P Hub and install its libraries, like H5PEditorAjax::libraryInstall().
   *
   * Call at most once per request: core caches temporary upload path statically per request.
   *
   * @param string $machine_name Machine name of the content type.
   * @return bool Whether the package was valid and saved; core's messages tell why not.
   */
  public static function install_hub_package($machine_name) {
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    $core = $plugin->get_h5p_instance('core');
    $core->mayUpdateLibraries(TRUE);

    $path = $interface->getUploadedH5pPath();
    $response = $interface->fetchExternalData(
      H5PHubEndpoints::createURL(H5PHubEndpoints::CONTENT_TYPES . $machine_name),
      NULL,
      TRUE,
      empty($path) ? TRUE : $path
    );

    $valid = FALSE;
    if ($response) {
      $valid = (new H5PValidator($interface, $core))->isValidPackage(TRUE, FALSE);
    }

    if ($valid) {
      (new H5PStorage($interface, $core))->savePackage(NULL, NULL, TRUE);
    }

    // Temporary paths are static per request, so always clean up; @ as core sometimes removes .h5p file.
    H5PCore::deleteFileTree($interface->getUploadedH5pFolderPath());
    @unlink($path);

    return $valid;
  }

  /**
   * Delete cached assets that include library version; H5PFrameworkInterface::deleteLibrary() leaves them.
   *
   * @param int $library_id
   */
  public static function delete_library_cached_assets($library_id) {
    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    $core = $plugin->get_h5p_instance('core');

    if ($core->aggregateAssets && ($hashes = $interface->deleteCachedAssets($library_id))) {
      $core->fs->deleteCachedAssets($hashes);
    }
  }

  /**
   * Build relative traversal between folders, e.g. "/../../../h5p_network" from uploads/sites/4/h5p.
   *
   * H5peditor::getLibraryData() prefixes upgrade script path with blog's H5PCore::$url without hook, so only
   * traversal reaches network folder. Valid as path and URL, as uploads URL mirrors uploads directory.
   *
   * @param string $from Folder the result is relative to.
   * @param string $to   Folder to reach.
   * @return string Traversal starting with "/".
   */
  public static function relative_path($from, $to) {
    $from_segments = explode('/', trim(str_replace('\\', '/', $from), '/'));
    $to_segments = explode('/', trim(str_replace('\\', '/', $to), '/'));

    // Drop the segments the two have in common.
    while (!empty($from_segments) && !empty($to_segments) && $from_segments[0] === $to_segments[0]) {
      array_shift($from_segments);
      array_shift($to_segments);
    }

    return '/' . str_repeat('../', count($from_segments)) . implode('/', $to_segments);
  }
}
