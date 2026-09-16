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
   * Render the network level H5P management page with H5P Hub client for management.
   */
  public function render_management_page() {
    if (!current_user_can('manage_network')) {
      wp_die(esc_html__('You do not have permission to manage H5P content types.', 'h5p'));
    }

    // Set up H5PIntegration and enqueue editor scripts, but not its stylesheets: those load inside the iframe.
    $content = new H5PContentAdmin('h5p');
    $content->add_editor_assets();

    $plugin = H5P_Plugin::get_instance();
    $management_settings = array(
      'style' => plugins_url('h5p/admin/styles/h5p-hub-management.css') . '?ver=' . H5P_Plugin::VERSION,
      'hubPanelLabel' => __('Manage content type', 'h5p'),
    );
    $plugin->print_settings($management_settings, 'H5PHubManagement');

    include 'views/network-management.php';

    H5P_Plugin_Admin::add_script('h5p-jquery', 'h5p-php-library/js/jquery.js');
    wp_enqueue_script(
      $plugin->asset_handle('hub-management'),
      plugins_url('h5p/admin/scripts/h5p-hub-management.js'),
      array($plugin->asset_handle('editor')),
      H5P_Plugin::VERSION
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
