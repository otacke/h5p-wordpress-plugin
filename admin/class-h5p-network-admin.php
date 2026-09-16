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
   *
   * In network mode the library screens are added as well, since libraries are
   * then managed network wide instead of per blog.
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

      // Installing and updating content types writes to the network level
      // libraries, so this is offered to network admins only.
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
   *
   * Shows the H5P Hub client, so content types shared by every blog can be
   * installed and updated. Creating content is not offered here.
   */
  public function render_management_page() {
    if (!current_user_can('manage_network')) {
      wp_die(esc_html__('You do not have permission to manage H5P content types.', 'h5p'));
    }

    // Sets up H5PIntegration, including the editor assets list, and enqueues the
    // H5P core scripts plus the editor scripts that belong on the page itself.
    // The editor and hub stylesheets are deliberately not enqueued here: they
    // are listed in H5PIntegration.editor.assets and loaded inside the iframe,
    // which keeps them from restyling the WordPress admin page.
    $content = new H5PContentAdmin('h5p');
    $content->add_editor_assets();

    // Loaded inside the iframe, after the editor stylesheets, to hide the parts
    // of the hub that lead on to creating content.
    $plugin = H5P_Plugin::get_instance();
    $management_settings = array(
      'style' => plugins_url('h5p/admin/styles/h5p-hub-management.css') . '?ver=' . H5P_Plugin::VERSION,
      // Replaces the hub's own "Select content type" label, since content types
      // are managed here rather than picked to author with.
      'hubPanelLabel' => __('Manage content type', 'h5p'),
    );
    $plugin->print_settings($management_settings, 'H5PHubManagement');

    include 'views/network-management.php';

    // Enqueued after the view, as the other admin pages do. Depends on the
    // editor glue from add_editor_assets(): its document ready handler puts
    // H5PEditor.assets and H5PEditor.contentLanguage in place, which the iframe
    // is built from, and both handlers run on document ready.
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

    $migrate = new H5P_Network_Migrate_To_Network();

    try {
      // TODO: Should run in batches and give progress info
      $migrate->migrateToNetwork();
    }
    catch (Exception $exception) {
      $rolled_back = false;

      // Only steps 1 and 2 can be undone. They just add the network libraries
      // directory and the network tables, so discarding both restores the
      // pre-migration state. Note that migrateToLocal() must NOT be used here:
      // it would copy the half-migrated network state back into every blog.
      if ($migrate->isRollbackPossible()) {
        try {
          $demigrate = new H5P_Network_Migrate_To_Local();
          $demigrate->deleteNetworkFilesDirectory();
          $demigrate->dropNetworkTables();
          $rolled_back = true;
        }
        catch (Exception $rollback_exception) {
          error_log('H5P network migration rollback: ' . $rollback_exception->getMessage());
        }
      }

      error_log(
        sprintf(
          'H5P network migration failed in step %s: %s',
          $migrate->getFailedStep(),
          $exception->getMessage()
        )
      );

      wp_send_json_error(
        array(
          'message'    => $exception->getMessage(),
          'step'       => $migrate->getFailedStep(),
          'rolledBack' => $rolled_back,
        ),
        H5PCommons::HTTP_OK
      );

      // wp_send_json_error() exits, but never enable network mode on failure
      // should that ever not hold.
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
        'message' => sprintf(
          __('Migrated libraries to network level.', 'h5p')
        ),
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
   * Who may manage libraries depends on this, so the capabilities of every blog
   * are re-assigned to match. Enabling revokes manage_h5p_libraries from blog
   * roles, disabling grants it back to those with manage_options.
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
