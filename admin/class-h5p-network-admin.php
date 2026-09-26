<?php

/**
 * H5P_Network_Admin
 *
 * Super-admin (Network Admin) UI for enabling/disabling H5P "Network Mode" — central, network-level
 * library management. Lives under Network Admin > Settings and is only registered on multisite.
 * Delegates migration logic to H5P_Network_Migrate_To_Network and H5P_Network_Migrate_To_Local.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Admin {

  public function __construct() {
    add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
    add_action('wp_ajax_h5p_migrate_to_network', array($this, 'handle_migrate_to_network'));
    add_action('wp_ajax_h5p_migrate_to_local', array($this, 'handle_migrate_to_local'));
  }

  /**
   * Add the "H5P Network" page under the network Settings menu.
   */
  public function add_network_admin_menu() {
    $this->library = NULL;

    add_submenu_page(
      'settings.php',
      __('H5P Network', 'h5p'),
      __('H5P Network', 'h5p'),
      'manage_network',
      'h5p_network',
      array($this, 'render_settings_page')
    );

    if (H5PCommons::is_network_enabled()) {
      $this->library = H5PLibraryAdmin::create('h5p');
      $libraries_page = add_submenu_page(
        'settings.php',
        __('H5P Libraries', 'h5p'),
        __('H5P Libraries', 'h5p'),
        H5PCommons::current_user_can_manage_libraries() ? 'manage_network' : 'manage_h5p_libraries',
        'h5p_libraries',
        array($this->library, 'display_libraries_page')
      );
      add_action('load-' . $libraries_page, array($this->library, 'process_libraries'));

      // Installing and updating content types writes to network level libraries, so offered to network admins only.
      add_menu_page(
        __('H5P Management', 'h5p'),
        __('H5P Management', 'h5p'),
        'manage_network',
        'h5p_management',
        array($this, 'render_management_page'),
        'none'
      );
    }
  }

  /**
   * Render the network level H5P management page.
   */
  public function render_management_page() {
    if (!current_user_can('manage_network')) {
      wp_die(esc_html__('You do not have permission to manage H5P content types.', 'h5p'));
    }

    // Set up H5PIntegration and enqueue the editor scripts, so H5PEditor.getAjaxUrl() is available.
    $content = new H5PContentAdmin('h5p');
    $content->add_editor_assets();

    $plugin = H5P_Plugin::get_instance();
    $library_settings = array('l10n' => $this->get_library_l10n());
    $plugin->print_settings($library_settings, 'H5PNetworkLibraries');

    $overview = $this->get_library_overview();
    include 'views/network-management.php';

    H5P_Plugin_Admin::add_script('h5p-jquery', 'h5p-php-library/js/jquery.js');
    wp_enqueue_script(
      $plugin->asset_handle('plugin-confirmation-dialog'),
      plugins_url('h5p/admin/scripts/h5p-confirmation-dialog.js'),
      array(),
      H5P_Plugin::VERSION
    );
    wp_enqueue_style(
      $plugin->asset_handle('plugin-confirmation-dialog'),
      plugins_url('h5p/admin/styles/h5p-confirmation-dialog.css'),
      array(),
      H5P_Plugin::VERSION
    );
    wp_enqueue_script(
      $plugin->asset_handle('network-libraries'),
      plugins_url('h5p/admin/scripts/h5p-network-libraries.js'),
      array($plugin->asset_handle('editor')),
      H5P_Plugin::VERSION
    );
    wp_enqueue_style(
      $plugin->asset_handle('network-libraries'),
      plugins_url('h5p/admin/styles/h5p-network-libraries.css'),
      array(),
      H5P_Plugin::VERSION
    );
  }

  /**
   * Collect the installed and available libraries for the network management page.
   *
   * @return array
   */
  private function get_library_overview() {
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');
    $interface = $plugin->get_h5p_instance('interface');

    $hub_is_enabled = get_option('h5p_hub_is_enabled', TRUE) == TRUE;

    // Keep the hub cache fresh, like H5PEditorAjax::isContentTypeCacheUpdated() does.
    if ($hub_is_enabled && $interface->getOption('content_type_cache_updated_at', 0) + 60 * 60 * 24 * 7 < time()) {
      try {
        $core->updateContentTypeCache();
      }
      catch (Exception $exception) {
        // Not fatal: the existing cache is used instead.
        error_log('H5P network management: ' . $exception->getMessage());
      }
    }

    $hub = array();
    foreach ((array) $this->get_hub_cache() as $cached) {
      if (!isset($hub[$cached->machine_name])
          || $this->compare_library_versions($cached, $hub[$cached->machine_name]) > 0) {
        $hub[$cached->machine_name] = $cached;
      }
    }

    $has_icons = $this->load_library_icons();
    $installed = array();
    $installed_names = array();
    foreach ($interface->loadLibraries() as $name => $versions) {
      $installed_names[$name] = TRUE;

      // loadLibraries() does not sort, so find the newest installed version explicitly.
      $newest = $versions[0];
      foreach ($versions as $version) {
        if ($this->compare_library_versions($version, $newest) > 0) {
          $newest = $version;
        }
      }

      foreach ($versions as $version) {
        $installed[] = array(
          'id' => (int) $version->id,
          'machineName' => $version->name,
          'title' => $version->title,
          'majorVersion' => (int) $version->major_version,
          'minorVersion' => (int) $version->minor_version,
          'patchVersion' => (int) $version->patch_version,
          'runnable' => (bool) $version->runnable,
          'icon' => $this->get_library_icon($version, $has_icons, $hub, $interface),
          // Only the newest installed version of a library can offer an update.
          'update' => ($version === $newest) ? $this->get_available_update($version, $hub) : NULL,
        );
      }
    }

    $available = array();
    foreach ($hub as $machine_name => $cached) {
      if (isset($installed_names[$machine_name])) {
        continue;
      }
      $available[] = array(
        'machineName' => $cached->machine_name,
        'title' => $cached->title,
        'icon' => !empty($cached->icon) ? $cached->icon : null,
        'majorVersion' => (int) $cached->major_version,
        'minorVersion' => (int) $cached->minor_version,
        'patchVersion' => (int) $cached->patch_version,
        'canInstall' => $this->is_hub_library_compatible($cached)
          && $this->can_install_hub_library($cached),
      );
    }

    usort($installed, array($this, 'sort_libraries'));
    usort($available, array($this, 'sort_libraries'));

    return array(
      'installed' => $installed,
      'available' => $available,
      'hubIsEnabled' => $hub_is_enabled,
    );
  }

  /**
   * @return array
   */
  private function get_hub_cache() {
    return (array) (new H5PEditorWordPressAjax())->getContentTypeCache();
  }

  /**
   * loadLibraries() does not return has_icon, so the values are loaded separately.
   *
   * @return array
   */
  private function load_library_icons() {
    global $wpdb;

    $table = H5PCommons::build_full_db_table_name('h5p_libraries');
    $has_icons = array();
    foreach ((array) $wpdb->get_results("SELECT id, has_icon FROM {$table}") as $row) {
      $has_icons[(int) $row->id] = (bool) $row->has_icon;
    }
    return $has_icons;
  }

  /**
   * Get the icon of an installed library: local icon, hub icon or none.
   *
   * @param object $library
   * @param array $has_icons
   * @param array $hub
   * @param object $interface
   *
   * @return string|null
   */
  private function get_library_icon($library, $has_icons, $hub, $interface) {
    if (!empty($has_icons[(int) $library->id])) {
      $folder = H5PCore::libraryToFolderName(array(
        'machineName' => $library->name,
        'majorVersion' => (int) $library->major_version,
        'minorVersion' => (int) $library->minor_version,
        'patchVersion' => (int) $library->patch_version,
        'patchVersionInFolderName' => FALSE,
      ));
      return $interface->getLibraryFileUrl($folder, 'icon.svg');
    }

    if (isset($hub[$library->name]) && !empty($hub[$library->name]->icon)) {
      return $hub[$library->name]->icon;
    }

    return null;
  }

  /**
   * Find a newer, compatible and installable hub version of an installed library.
   *
   * @param object $library
   * @param array $hub
   *
   * @return array|null
   */
  private function get_available_update($library, $hub) {
    if (!isset($hub[$library->name])) {
      return NULL;
    }

    $cached = $hub[$library->name];
    if ($this->compare_library_versions($cached, $library) <= 0
        || !$this->is_hub_library_compatible($cached)
        || !$this->can_install_hub_library($cached)) {
      return NULL;
    }

    return array(
      'majorVersion' => (int) $cached->major_version,
      'minorVersion' => (int) $cached->minor_version,
      'patchVersion' => (int) $cached->patch_version,
      'isMinorOrMajor' => (int) $cached->major_version !== (int) $library->major_version
        || (int) $cached->minor_version !== (int) $library->minor_version,
    );
  }

  /**
   * Check that a hub library is compatible with the installed H5P core API version.
   *
   * @param object $cached
   *
   * @return bool
   */
  private function is_hub_library_compatible($cached) {
    $required_major = (int) $cached->h5p_major_version;
    $required_minor = (int) $cached->h5p_minor_version;
    return $required_major < H5PCore::$coreApi['majorVersion']
      || ($required_major === H5PCore::$coreApi['majorVersion']
          && $required_minor <= H5PCore::$coreApi['minorVersion']);
  }

  /**
   * Check that the current user may install a hub library, like H5peditor::canInstallContentType().
   *
   * @param object $cached
   *
   * @return bool
   */
  private function can_install_hub_library($cached) {
    return H5PCommons::current_user_can_manage_libraries()
      || ((bool) $cached->is_recommended && H5PCommons::current_user_can_install_recommended_libraries());
  }

  /**
   * Compare two library versions.
   *
   * @param object $a
   * @param object $b
   *
   * @return int
   */
  private function compare_library_versions($a, $b) {
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
   * Sort library rows by title, then by version.
   *
   * @param array $a
   * @param array $b
   *
   * @return int
   */
  private static function sort_libraries($a, $b) {
    $title = strcasecmp($a['title'], $b['title']);
    if ($title !== 0) {
      return $title;
    }
    $a_version = $a['majorVersion'] . '.' . $a['minorVersion'] . '.' . $a['patchVersion'];
    $b_version = $b['majorVersion'] . '.' . $b['minorVersion'] . '.' . $b['patchVersion'];
    return version_compare($a_version, $b_version);
  }

  /**
   * Format the version of a library row as major.minor.patch.
   *
   * @param array $library
   *
   * @return string
   */
  private function format_library_version($library) {
    return $library['majorVersion'] . '.' . $library['minorVersion'] . '.' . $library['patchVersion'];
  }

  /**
   * Format the name of a library row as title (machine name).
   *
   * @param array $library
   *
   * @return string
   */
  private function format_library_name($library) {
    return sprintf('%s (%s)', $library['title'], $library['machineName']);
  }

  /**
   * Get the strings the library grid script needs; the grids themselves are rendered server-side.
   *
   * @return array
   */
  private function get_library_l10n() {
    return array(
      'cancel' => __('Cancel', 'h5p'),
      'confirm' => __('Update', 'h5p'),
      'requestFailed' => __('The library could not be installed or updated. Please try again.', 'h5p'),
      'working' => __('Working...', 'h5p'),
      'dismiss' => __('Dismiss this notice.', 'h5p'),
    );
  }

  /**
   * Render network settings page.
   */
  public function render_settings_page() {
    $save = filter_input( INPUT_POST, 'save_network_settings', FILTER_SANITIZE_SPECIAL_CHARS );

    if ($save !== null) {
      check_admin_referer( 'h5p_network_settings', 'save_network_settings' );
    }

    // Read back the stored state, so the form always shows what is in effect.
    $enabled = H5PCommons::is_network_enabled();

    include 'views/network-settings.php';
  }

  /**
   * Handle AJAX request to migrate libraries to network level.
   */
  public function handle_migrate_to_network() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network')) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    // Migrates copies and deletes every library file of every blog, which can
    // take longer than the configured limit. Not honoured by every host.
    @set_time_limit(0);
    ignore_user_abort(true);

    $migrate = new H5P_Network_Migrate_To_Network();

    $state_before = H5P_Network_Migrate_To_Network::getState();
    $phase = $state_before['phase'];

    try {
      $progress = $migrate->migrateNextBatch();
    }
    catch (Exception $exception) {
      $rolled_back = false;

      if (H5P_Network_Migrate_To_Network::isPhaseRollbackPossible($phase)) {
        try {
          $demigrate = new H5P_Network_Migrate_To_Local();
          $demigrate->deleteNetworkFilesDirectory();
          $demigrate->dropNetworkTables();
          H5P_Network_Migrate_To_Network::clearState();
          $rolled_back = true;
        }
        catch (Exception $rollback_exception) {
          error_log('H5P network migration rollback: ' . $rollback_exception->getMessage());
        }
      }

      error_log(
        sprintf(
          'H5P network migration failed in phase %s (step %s): %s',
          $phase,
          $migrate->getFailedStep(),
          $exception->getMessage()
        )
      );

      wp_send_json_error(
        array(
          'message'    => $exception->getMessage(),
          'step'       => $migrate->getFailedStep(),
          'phase'      => $phase,
          'rolledBack' => $rolled_back,
        ),
        H5PCommons::HTTP_OK
      );

      return;
    }

    if (!$progress['done']) {
      // More blogs to work through, so client calls again.
      wp_send_json_success(
        array(
          'phase'      => $progress['phase'],
          'percentage' => $progress['percentage'],
          'done'       => false,
          'nonce'      => wp_create_nonce('h5p_network_ajax'),
        )
      );
      return;
    }

    $this->set_network_mode(true);

    if (!(defined('H5P_DISABLE_AGGREGATION') && H5P_DISABLE_AGGREGATION === true)) {
      try {
        $migrate->createCachedAssets();
      }
      catch (Exception $exception) {
        // Not fatal: cached assets are created lazily when content is viewed.
        error_log('H5P network migration: ' . $exception->getMessage());
      }
    }

    wp_send_json_success(
      array(
        'message' => __('Migrated libraries to network level.', 'h5p'),
        'done'    => true,
      )
    );
  }

  /**
   * Handle AJAX request to migrate libraries back to local (blog) level.
   */
  public function handle_migrate_to_local() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network')) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    // Migrating copies and deletes every library file of every blog, which can
    // take longer than the configured limit. Not honoured by every host.
    @set_time_limit(0);
    ignore_user_abort(true);

    $demigrate = new H5P_Network_Migrate_To_Local();
    $demigrate->migrateToLocal();

    $this->set_network_mode(false);

    if (!(defined('H5P_DISABLE_AGGREGATION') && H5P_DISABLE_AGGREGATION === true)) {
      try {
        $demigrate->createCachedAssets();
      }
      catch (Exception $exception) {
        // Not fatal: cached assets are created lazily when content is viewed.
        error_log('H5P network demigration: ' . $exception->getMessage());
      }
    }

    wp_send_json_success();
  }

  /**
   * Switch network mode on or off.
   *
   * @param bool $enabled Whether network mode should be enabled.
   */
  private function set_network_mode($enabled) {
    H5PCommons::set_network_enabled($enabled);
    H5P_Plugin::assign_capabilities_all_blogs();
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
