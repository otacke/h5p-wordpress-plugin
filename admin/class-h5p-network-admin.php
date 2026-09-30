<?php

/**
 * H5P_Network_Admin
 *
 * Super-admin (Network Admin) UI for enabling/disabling H5P "Network Mode" — central, network-level
 * library management. Lives under Network Admin > Settings and is only registered on multisite.
 * Delegates migration logic to H5P_Network_Migrate_To_Network and H5P_Network_Migrate_To_Local, and library
 * management to H5P_Network_Library_Overview, H5P_Network_Library_Deletion, H5P_Network_Library_Installer and
 * H5P_Network_Content_Cache.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Admin {

  /**
   * Decides which library versions can be deleted, and deletes them.
   *
   * @var H5P_Network_Library_Deletion
   */
  private $deletion;

  /**
   * Installs and updates libraries from the H5P Hub and uploaded packages.
   *
   * @var H5P_Network_Library_Installer
   */
  private $installer;

  /**
   * Collects what the management page shows.
   *
   * @var H5P_Network_Library_Overview
   */
  private $overview;

  public function __construct() {
    $this->deletion = new H5P_Network_Library_Deletion();
    $this->installer = new H5P_Network_Library_Installer();
    $this->overview = new H5P_Network_Library_Overview($this->deletion, $this->installer);

    add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
    add_action('wp_ajax_h5p_migrate_to_network', array($this, 'handle_migrate_to_network'));
    add_action('wp_ajax_h5p_migrate_to_local', array($this, 'handle_migrate_to_local'));
    add_action('wp_ajax_h5p_network_library_delete', array($this, 'handle_delete_library'));
    add_action('wp_ajax_h5p_network_library_delete_all', array($this, 'handle_delete_all_libraries'));
    add_action('wp_ajax_h5p_network_library_install', array($this, 'handle_library_install'));
    add_action('wp_ajax_h5p_network_library_upload', array($this, 'handle_library_upload'));
    add_action('wp_ajax_h5p_network_update_content_type_cache', array($this, 'handle_update_content_type_cache'));
    add_action('wp_ajax_h5p_network_rebuild_cache', array($this, 'handle_rebuild_cache'));
  }

  /**
   * Add the "H5P Network" page under the network Settings menu.
   */
  public function add_network_admin_menu() {
    add_submenu_page(
      'settings.php',
      __('H5P Network', 'h5p'),
      __('H5P Network', 'h5p'),
      'manage_network',
      'h5p_network',
      array($this, 'render_settings_page')
    );

    if (H5PCommons::is_network_enabled()) {
      // Installing and updating content types writes to network level libraries, so offered to network admins only.
      // Registered without a parent, so it has no menu entry of its own and opens at admin.php?page=h5p_management.
      $management_page = add_submenu_page(
        '',
        __('H5P Management', 'h5p'),
        __('H5P Management', 'h5p'),
        'manage_network',
        'h5p_management',
        array($this, 'render_management_page')
      );
      add_action('load-' . $management_page, array($this, 'set_management_page_title'));
      // The page stays registered and loadable, but without this, get_admin_page_parent() would find it under
      // the empty parent and reset the parent_file set by highlight_management_menu_entry(), closing Settings.
      remove_submenu_page('', 'h5p_management');

      // The Settings entry links to the management page; a slug with a query is used as the link as it is.
      add_submenu_page(
        'settings.php',
        __('H5P Network Libraries', 'h5p'),
        __('H5P Network Libraries', 'h5p'),
        'manage_network',
        'admin.php?page=h5p_management'
      );

      add_filter('parent_file', array($this, 'highlight_management_menu_entry'));
      add_filter('submenu_file', array($this, 'highlight_management_submenu_entry'));
    }
  }

  /**
   * Set the title of the management page, which get_admin_page_title() cannot find for a page without a parent.
   */
  public function set_management_page_title() {
    global $title;

    $title = __('H5P Management', 'h5p');
  }

  /**
   * Open the Settings menu on the management page, as the page has no menu entry of its own.
   *
   * @param string $parent_file Parent menu file of the current page.
   *
   * @return string
   */
  public function highlight_management_menu_entry($parent_file) {
    global $plugin_page;

    return $plugin_page === 'h5p_management' ? 'settings.php' : $parent_file;
  }

  /**
   * Mark the Settings > H5P network libraries entry as current on the management page.
   *
   * @param string|null $submenu_file Submenu file of the current page.
   *
   * @return string|null
   */
  public function highlight_management_submenu_entry($submenu_file) {
    global $plugin_page;

    return $plugin_page === 'h5p_management' ? 'admin.php?page=h5p_management' : $submenu_file;
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
    $library_settings = array(
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('h5p_network_ajax'),
      'upgrade' => $this->overview->get_upgrade_settings(),
      'l10n' => $this->overview->get_library_l10n(),
    );
    $plugin->print_settings($library_settings, 'H5PNetworkLibraries');

    $overview = $this->overview->get_library_overview();
    $interface = $plugin->get_h5p_instance('interface');
    // Read after the overview, which may have refreshed the content type cache.
    $content_type_cache_updated_at = (int) $interface->getOption('content_type_cache_updated_at', 0);
    // Contents on all blogs whose cache is missing; the rebuild box is shown only while there are any.
    $not_cached = (int) $interface->getNumNotFiltered();
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

      // Decided by the step that failed, not by the phase: the database phase runs step 3, which changes blogs.
      if ($migrate->isRollbackPossible()) {
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
   * Handle AJAX request to delete an installed library version.
   */
  public function handle_delete_library() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network') || !H5PCommons::current_user_can_manage_libraries()) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    // The grid may be stale, so recheck deletability with fresh data.
    $model = $this->deletion->get_deletion_model();
    $library = isset($model['libraries'][$id]) ? $model['libraries'][$id] : null;
    if ($library === null) {
      wp_send_json_error(
        array('message' => __('This library is no longer installed.', 'h5p')),
        404
      );
    }

    $deletion = $this->deletion->is_library_deletable($library, $model);
    if (!$deletion['deletable']) {
      // Same message as the blog level library admin, so the translations stay in sync.
      wp_send_json_error(
        array(
          'message' => __(
            'This Library is used by content or other libraries and can therefore not be deleted.',
            'h5p'
          ),
        )
      );
    }

    $this->deletion->delete_library_version($id, $deletion['alsoDelete']);

    wp_send_json_success();
  }

  /**
   * Delete every deletable library version, and those that become deletable by that, until the time is up.
   *
   * See H5P_Network_Library_Deletion::delete_deletable_libraries().
   *
   * @param float $deadline microtime(TRUE) after which no new pass is started.
   * @param bool $dry_run Only record what would be deleted, for testing the passes on real data.
   *
   * @return array With 'deleted', the names and versions deleted, and 'more', whether time ran out
   *               while the last pass still deleted something.
   */
  public function delete_deletable_libraries($deadline, $dry_run = false) {
    return $this->deletion->delete_deletable_libraries($deadline, $dry_run);
  }

  /**
   * Handle AJAX request to delete every deletable library version, one time budget at a time.
   *
   * Answers 'more' while the page should repeat the request.
   */
  public function handle_delete_all_libraries() {
    $this->verify_library_management_request();

    wp_send_json_success(
      $this->delete_deletable_libraries(microtime(TRUE) + H5PLibraryAdmin::UPGRADE_BATCH_TIMEOUT)
    );
  }

  /**
   * Handle AJAX request to install or update libraries from an uploaded .h5p package.
   */
  public function handle_library_upload() {
    $this->verify_library_management_request();

    $result = $this->installer->upload_library_package(
      isset($_FILES['h5p_file']) ? (int) $_FILES['h5p_file']['error'] : UPLOAD_ERR_NO_FILE,
      filter_input(INPUT_POST, 'h5p_upgrade_only') ? TRUE : FALSE
    );

    if (!$result['success']) {
      wp_send_json_error(array('messages' => $result['messages']));
    }

    wp_send_json_success(array('messages' => $result['messages']));
  }

  /**
   * Install one content type from the H5P Hub.
   *
   * See H5P_Network_Library_Installer::install_hub_library().
   *
   * @param string $machine_name
   *
   * @return array With 'status' and 'messages'.
   */
  public function install_hub_library($machine_name) {
    return $this->installer->install_hub_library($machine_name);
  }

  /**
   * Handle AJAX request to install one content type from the H5P Hub.
   *
   * Always answers success, with the outcome in the data: a failed item is data for the bulk queue,
   * not a fatal error.
   */
  public function handle_library_install() {
    $this->verify_library_management_request();

    // Hub downloads are slow, like core's.
    @set_time_limit(0);

    $machine_name = filter_input(INPUT_POST, 'machineName', FILTER_SANITIZE_SPECIAL_CHARS);

    wp_send_json_success($this->install_hub_library($machine_name));
  }

  /**
   * Handle AJAX request to update the content type cache from the H5P Hub.
   */
  public function handle_update_content_type_cache() {
    $this->verify_library_management_request();

    $result = $this->installer->update_content_type_cache();

    if (!$result['success']) {
      wp_send_json_error(array('messages' => $result['messages']));
    }

    wp_send_json_success(array('messages' => $result['messages']));
  }

  /**
   * Handle AJAX request to rebuild the content caches (filtered parameters) of all blogs, one batch at a time.
   */
  public function handle_rebuild_cache() {
    $this->verify_library_management_request();

    $left = (new H5P_Network_Content_Cache())->rebuild(microtime(TRUE) + H5PLibraryAdmin::UPGRADE_BATCH_TIMEOUT);

    wp_send_json_success(array('left' => $left));
  }

  /**
   * Verify that an AJAX request may manage the network libraries, or end it with an error.
   *
   * Same checks as handle_delete_library(): the nonce, and the capabilities for the network libraries.
   */
  private function verify_library_management_request() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network') || !H5PCommons::current_user_can_manage_libraries()) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }
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
