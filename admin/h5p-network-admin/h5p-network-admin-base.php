<?php

/**
 * H5P_Network_Admin_Base
 *
 * Shared base class for network migration operations. Provides common
 * helpers for path resolution, table name resolution, and AJAX nonce
 * verification.
 * @package H5P
 * @since 1.19.0
 */
abstract class H5P_Network_Admin_Base {
  use H5PUtils;

  /**
   * Get the network-level libraries directory path.
   *
   * @return string
   */
  protected function getH5PNetworkPath() {
    return wp_upload_dir()['basedir'] . '/h5p_network';
  }

  /**
   * Get the network-level libraries directory path.
   *
   * @return string
   */
  protected function getNetworkLibrariesPath() {
    return  $this->getH5PNetworkPath() . '/libraries';
  }

  /**
   * Get the network-level libraries database table name.
   *
   * @return string
   */
  protected function getNetworkLibrariesTableName() {
    global $wpdb;
    return $wpdb->prefix . 'h5p_network_h5p_libraries';
  }

  /**
   * @return string
   */
  protected function getNetworkLibrariesCachedassetsTableName() {
    global $wpdb;
    return $wpdb->prefix . 'h5p_network_h5p_libraries_cachedassets';
  }

  /**
   * @return string
   */
  protected function getNetworkLibrariesLanguagesTableName() {
    global $wpdb;
    return $wpdb->prefix . 'h5p_network_h5p_libraries_languages';
  }

  /**
   * @return string
   */
  protected function getNetworkLibrariesLibrariesTableName() {
    global $wpdb;
    return $wpdb->prefix . 'h5p_network_h5p_libraries_libraries';
  }

  /**
   * Verify the AJAX request nonce.
   *
   * @throws Exception If the nonce is invalid.
   */
  protected function verifyNetworkNonce() {
    check_ajax_referer('h5p_network_ajax', 'nonce', true);
  }
}
