<?php
/**
 * H5P_Network_Mode
 *
 * Network mode flag and capability checks depending on it.
 *
 * In network mode only network admins may write shared libraries; H5P_Plugin::library_capability_source() maps
 * roles, checks here enforce it per request, as roles are per blog and users can get capabilities directly.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Network_Mode {

  /**
   * Cached network enable flag to avoid repeated database calls.
   *
   * @var bool|null
   */
  private static $is_network_enabled = null;

  /**
   * Whether network mode is enabled.
   *
   * @since 1.18.0
   * @return bool
   */
  public static function is_network_enabled() {
    if (null === self::$is_network_enabled) {
      self::$is_network_enabled = get_site_option('h5p_network_enabled', false);
    }

    return (bool) self::$is_network_enabled;
  }

  /**
   * Turn network mode on or off, keeping cached flag in step so same request sees new state.
   *
   * @param bool $enabled Whether network mode should be enabled.
   */
  public static function set_network_enabled($enabled) {
    $enabled = (bool) $enabled;

    update_site_option('h5p_network_enabled', $enabled);
    self::$is_network_enabled = $enabled;

    /**
     * Fires after the network mode was switched, so everything built for the previous mode is dropped.
     *
     * @since 1.18.0
     * @param bool $enabled Whether network mode is enabled now.
     */
    do_action('h5p_network_mode_changed', $enabled);
  }

  /**
   * Get current mode, answering everything differing between local and network mode; one stateless object each.
   *
   * @since 1.18.0
   * @return H5P_Mode
   */
  public static function mode() {
    static $modes = array();

    $key = self::is_network_enabled() ? 'network' : 'local';
    if (!isset($modes[$key])) {
      $modes[$key] = ($key === 'network') ? new H5P_Mode_Network() : new H5P_Mode_Local();
    }

    return $modes[$key];
  }

  /**
   * Whether H5P Hub access is enabled on the current blog.
   *
   * @since 1.18.0
   * @return bool
   */
  public static function is_hub_enabled() {
    return get_option('h5p_hub_is_enabled', TRUE) == TRUE;
  }

  /**
   * Whether current user may manage libraries.
   *
   * @return bool
   */
  public static function current_user_can_manage_libraries() {
    return self::current_user_can_library_capability('manage_h5p_libraries');
  }

  /**
   * Whether current user may install recommended libraries.
   *
   * @return bool
   */
  public static function current_user_can_install_recommended_libraries() {
    return self::current_user_can_library_capability('install_recommended_h5p_libraries');
  }

  /**
   * Whether current user holds capability that writes to libraries; network admins only in network mode.
   *
   * @param string $capability Capability to check.
   * @return bool
   */
  private static function current_user_can_library_capability($capability) {
    if (!self::mode()->current_user_may_write_libraries()) {
      return false;
    }

    return current_user_can($capability);
  }
}
