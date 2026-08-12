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
    add_submenu_page(
      'settings.php',
      __('H5P Network', 'h5p'),
      __('H5P Network', 'h5p'),
      'manage_network',
      'h5p_network',
      array($this, 'render_settings_page')
    );
  }

  /**
   * Render network settings page.
   */
  public function render_settings_page() {
    $save = filter_input( INPUT_POST, 'save_network_settings', FILTER_SANITIZE_SPECIAL_CHARS );

    if ($save !== null) {
      check_admin_referer( 'h5p_network_settings', 'save_network_settings' );
      $enabled = filter_input( INPUT_POST, 'h5p_network_enabled', FILTER_VALIDATE_BOOLEAN );
      update_site_option('h5p_network_enabled', $enabled);
    } else {
      $enabled = get_site_option('h5p_network_enabled', true);
    }

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
      // TODO: It will depend on where we exited!!!
      //$demigrate = new H5P_Network_Migrate_To_Local();
      //$demigrate->migrateToLocal();

      set_transient(
        'h5p_network_migration_error',
        $exception->getMessage(),
        HOUR_IN_SECONDS
      );

      wp_send_json_error(
        array('message' => $exception->getMessage()),
        H5PCommons::HTTP_OK
      );
      return;
    }

    update_site_option('h5p_network_enabled', true);

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

    update_site_option('h5p_network_enabled', false);

    wp_send_json_success();
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
