<?php
/**
 * H5P_Core_Adapter
 *
 * Every place where the plugin relies on how H5P core works inside (call order, side effects, per-request static
 * state) rather than on its interfaces alone. h5p-php-library and h5p-editor-php-library are never changed, so
 * when a core update changes these internals, this is the one class to adapt.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Core_Adapter {

  /**
   * Download a content type from the H5P Hub and install its libraries, like H5PEditorAjax::libraryInstall().
   *
   * Repeats core's order with public calls only, as that method also answers the AJAX request itself. One per
   * request: core caches the temporary upload path statically per request.
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

    // The temporary paths are static per request, so clean up on every exit path. Core removes the
    // .h5p file in some cases, hence the @.
    H5PCore::deleteFileTree($interface->getUploadedH5pFolderPath());
    @unlink($path);

    return $valid;
  }

  /**
   * Delete the cached assets that include a library version.
   *
   * H5PFrameworkInterface::deleteLibrary() leaves them behind; core deletes them itself only when it replaces a
   * library (H5PCore::saveLibraries()).
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
   * Build the relative traversal from one folder to another, e.g. "/../h5p_network" from uploads/h5p to
   * uploads/h5p_network, or "/../../../h5p_network" from uploads/sites/4/h5p.
   *
   * H5peditor::getLibraryData() prefixes the upgrade script path with H5PCore::$url, the blog's h5p URL, with no
   * hook in between; a traversal is the only way to reach the network folder. Valid as both a file system path
   * and a URL, because the uploads URL structure mirrors the uploads directory structure.
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
