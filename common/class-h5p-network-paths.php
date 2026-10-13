<?php
/**
 * H5P_Network_Paths
 *
 * Paths and URLs of the H5P folders, resolving network level from blog level in network mode.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Network_Paths {

  /** Name of network level H5P files directory, relative to uploads root. */
  const NETWORK_DIRECTORY_NAME = 'h5p_network';

  /**
   * Get path to network level H5P files folder in network wide uploads root, whichever blog is current.
   *
   * @return string
   */
  public static function get_h5p_network_path() {
    $upload_dir = wp_upload_dir();
    $base = self::strip_blog_from_uploads_base($upload_dir['basedir']);

    return $base . '/' . self::NETWORK_DIRECTORY_NAME;
  }

  /**
   * Get absolute URL for network level H5P files folder, as core and editor only leave URLs with scheme untouched.
   *
   * @return string
   */
  public static function get_h5p_network_url() {
    $upload_dir = wp_upload_dir();
    $base = self::strip_blog_from_uploads_base($upload_dir['baseurl']);
    $url = $base . '/' . self::NETWORK_DIRECTORY_NAME;

    return self::ensure_https($url);
  }

  /**
   * Update protocol of an absolute URL to HTTPS when the request is served over SSL.
   *
   * @since 1.18.0
   * @param string $url Absolute URL.
   * @return string URL with HTTPS protocol when served over SSL, otherwise unchanged.
   */
  public static function ensure_https($url) {
    if (is_ssl() && substr($url, 0, 5) !== 'https') {
      // Update protocol
      return 'https' . substr($url, 4);
    }

    return $url;
  }

  /**
   * Get path to libraries folder: network folder in network mode, else current blog's h5p folder.
   *
   * @since 1.18.0
   * @return string
   */
  public static function get_libraries_path() {
    return H5P_Network_Mode::mode()->get_libraries_base_path() . '/libraries';
  }

  /**
   * Get URL to libraries folder: absolute in network mode, site relative in local mode as core expects.
   *
   * @since 1.18.0
   * @return string
   */
  public static function get_libraries_url() {
    return H5P_Network_Mode::mode()->get_libraries_base_url() . '/libraries';
  }

  /**
   * Strip "sites/<blog id>" segment, which wp_upload_dir() adds on subsites, from uploads base path or URL.
   *
   * @param string $base Uploads base path or URL.
   * @return string Base without trailing per blog segment.
   */
  private static function strip_blog_from_uploads_base($base) {
    return preg_replace('#/sites/\d+$#', '', $base);
  }
}
