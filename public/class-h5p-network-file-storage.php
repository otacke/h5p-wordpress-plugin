<?php

/**
 * H5P_Network_File_Storage
 *
 * File storage for network mode, where one single "libraries" folder is shared
 * by every blog while content, exports and editor files stay with the blog.
 *
 * H5PCore accepts an H5PFileStorage instance in place of a path (see
 * H5PCore::__construct), which lets us split the storage root per operation
 * without touching H5P core. Library and cached asset operations are delegated
 * to a storage rooted at the network folder, everything else to a storage
 * rooted at the blog folder.
 *
 * Cached assets belong with the libraries: they are aggregated purely from
 * library files, the CSS url() rewriting in H5PDefaultStorage::cacheAssets()
 * is relative to the storage root, and h5p_libraries_cachedassets is a network
 * level table.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
class H5P_Network_File_Storage implements H5PFileStorage {

  /**
   * Storage rooted at this blog's h5p folder.
   *
   * @var \H5PDefaultStorage
   */
  private $local;

  /**
   * Storage rooted at the shared, network level h5p folder.
   *
   * @var \H5PDefaultStorage
   */
  private $network;

  /** @var string Absolute path to this blog's h5p folder. */
  private $localPath;

  /** @var string Absolute path to the shared, network level h5p folder. */
  private $networkPath;

  /**
   * @param string $localPath   Absolute path to this blog's h5p folder.
   * @param string $networkPath Absolute path to the network level h5p folder.
   */
  public function __construct($localPath, $networkPath) {
    $this->localPath = $localPath;
    $this->networkPath = $networkPath;
    $this->local = new H5PDefaultStorage($localPath);
    $this->network = new H5PDefaultStorage($networkPath);
  }

  // Libraries, shared across all blogs.

  public function saveLibrary($library) {
    return $this->network->saveLibrary($library);
  }

  public function deleteLibrary($library) {
    return $this->network->deleteLibrary($library);
  }

  /**
   * Note the third parameter: the interface declares two, but
   * H5PDefaultStorage accepts three and H5PExport calls it with three.
   */
  public function exportLibrary($library, $target, $developmentPath = NULL) {
    return $this->network->exportLibrary($library, $target, $developmentPath);
  }

  public function hasPresave($libraryName, $developmentPath = null) {
    return $this->network->hasPresave($libraryName, $developmentPath);
  }

  /**
   * The return value is used as a URL by H5peditor::getLibraryData(), which
   * prefixes it with H5PCore::$url, i.e. this blog's h5p URL. There is no hook
   * in between, so return a path that traverses from the blog folder to the
   * network folder instead of one relative to the network folder.
   */
  public function getUpgradeScript($machineName, $majorVersion, $minorVersion) {
    $script = $this->network->getUpgradeScript($machineName, $majorVersion, $minorVersion);

    return $script === NULL ? NULL : $this->getNetworkTraversal() . $script;
  }

  // Cached assets, aggregated from the shared libraries.

  public function cacheAssets(&$files, $key) {
    return $this->network->cacheAssets($files, $key);
  }

  public function getCachedAssets($key) {
    return $this->network->getCachedAssets($key);
  }

  public function deleteCachedAssets($keys) {
    return $this->network->deleteCachedAssets($keys);
  }

  /**
   * Both roots have to be writable, since libraries are installed into the
   * network folder while content is written to the blog folder.
   */
  public function hasWriteAccess() {
    return $this->local->hasWriteAccess() && $this->network->hasWriteAccess();
  }

  // Content, exports, editor files and temporary files, per blog.

  public function saveContent($source, $content) {
    return $this->local->saveContent($source, $content);
  }

  public function deleteContent($content) {
    return $this->local->deleteContent($content);
  }

  public function cloneContent($id, $newId) {
    return $this->local->cloneContent($id, $newId);
  }

  public function getTmpPath() {
    return $this->local->getTmpPath();
  }

  public function exportContent($id, $target) {
    return $this->local->exportContent($id, $target);
  }

  public function saveExport($source, $filename) {
    return $this->local->saveExport($source, $filename);
  }

  public function deleteExport($filename) {
    return $this->local->deleteExport($filename);
  }

  public function hasExport($filename) {
    return $this->local->hasExport($filename);
  }

  public function getContent($file_path) {
    return $this->local->getContent($file_path);
  }

  public function saveFile($file, $contentId) {
    return $this->local->saveFile($file, $contentId);
  }

  public function cloneContentFile($file, $fromId, $toId) {
    return $this->local->cloneContentFile($file, $fromId, $toId);
  }

  public function moveContentDirectory($source, $contentId = NULL) {
    return $this->local->moveContentDirectory($source, $contentId);
  }

  public function getContentFile($file, $contentId) {
    return $this->local->getContentFile($file, $contentId);
  }

  public function removeContentFile($file, $contentId) {
    return $this->local->removeContentFile($file, $contentId);
  }

  public function saveFileFromZip($path, $file, $stream) {
    return $this->local->saveFileFromZip($path, $file, $stream);
  }

  /**
   * Build the relative traversal from the blog folder to the network folder,
   * e.g. "/../h5p_network" for the main site and "/../../../h5p_network" for a
   * subsite. Valid as both a file system path and a URL, because the uploads
   * URL structure mirrors the uploads directory structure.
   *
   * @return string
   */
  private function getNetworkTraversal() {
    $local = explode('/', trim(str_replace('\\', '/', $this->localPath), '/'));
    $network = explode('/', trim(str_replace('\\', '/', $this->networkPath), '/'));

    // Drop the segments the two roots have in common.
    while (!empty($local) && !empty($network) && $local[0] === $network[0]) {
      array_shift($local);
      array_shift($network);
    }

    return '/' . str_repeat('../', count($local)) . implode('/', $network);
  }
}
