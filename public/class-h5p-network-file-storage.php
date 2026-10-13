<?php

/**
 * H5P_Network_File_Storage
 *
 * File storage for network mode: libraries shared by all blogs; content, exports and editor files per blog.
 *
 * Libraries and cached assets go to network rooted storage, rest to blog rooted storage. Cached assets must stay
 * with libraries, as CSS url() rewriting in H5PDefaultStorage::cacheAssets() is relative to storage root.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
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
   * Third parameter: interface declares two, but H5PDefaultStorage accepts three and H5PExport passes three.
   */
  public function exportLibrary($library, $target, $developmentPath = NULL) {
    return $this->network->exportLibrary($library, $target, $developmentPath);
  }

  public function hasPresave($libraryName, $developmentPath = null) {
    return $this->network->hasPresave($libraryName, $developmentPath);
  }

  /**
   * Traverse from blog folder, as H5peditor::getLibraryData() prefixes result with blog's H5PCore::$url, no hook.
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
   * Both roots must be writable: libraries go to network folder, content to blog folder.
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
   * Build relative traversal from blog folder to network folder, see H5P_Core_Adapter::relative_path().
   *
   * @return string
   */
  private function getNetworkTraversal() {
    return H5P_Core_Adapter::relative_path($this->localPath, $this->networkPath);
  }
}
