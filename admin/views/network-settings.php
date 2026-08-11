<?php
/**
 * Network settings view.
 *
 * @package   H5P
 * @author    Joubel <contact@joubel.com>
 * @license   MIT
 * @link      http://joubel.com
 * @copyright 2014 Joubel
 */
?>
<div class="wrap h5p-settings-container">
  <?php
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');

    $networkSettingsProperties = array(
      'networkToggleConfirmationDialogHeader' => $core->h5pF->t('Confirmation action'),
      'networkToggleConfirmationDialogMessageEnable' => $core->h5pF->t('This will migrate all local libraries (files + database) to the network level and revoke h5p library handling capabilities from blog admins. Do you want to enable the network settings?'),
      'networkToggleConfirmationDialogMessageDisable' => $core->h5pF->t('This will migrate all network libraries (files + database) back to the local level and grant h5p library handling capabilities to blog admins. Do you want to disable the network settings?'),
      'networkToggleConfirmationDialogCancelLabel' => $core->h5pF->t('Cancel'),
      'networkToggleConfirmationDialogConfirmLabel' => $core->h5pF->t('Confirm'),
      'nonce' => wp_create_nonce('h5p_network_ajax'),
      'ajaxPath' => admin_url('admin-ajax.php')
    );
    $plugin->print_settings($networkSettingsProperties, 'H5PNetworkSettingsProperties');

    \H5P_Plugin_Admin::add_script( 'confirmation-dialog', 'admin/scripts/h5p-confirmation-dialog.js' );
    \H5P_Plugin_Admin::add_style( 'confirmation-dialog', 'admin/styles/h5p-confirmation-dialog.css' );

    \H5P_Plugin_Admin::add_script( 'network-settings', 'admin/scripts/h5p-network-settings.js' );
    \H5P_Plugin_Admin::print_messages();
    ?>
  <h2><?php print esc_html(get_admin_page_title()); ?></h2>
  <?php if ($save !== NULL): ?>
    <div id="setting-error-settings_updated" class="updated settings-error">
      <p><strong><?php esc_html_e('Settings saved.', 'h5p') ?></strong></p>
    </div>
  <?php endif; ?>
  <form method="post">
    <table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><?php _e("H5P network", 'h5p'); ?></th>
          <td>
            <p>
              <?php
                echo esc_html(
                    $enabled
                      ? __('Network is enabled. Libraries are served from the network.', 'h5p')
                      : __('Network is disabled. Libraries are served from local blogs.', 'h5p')
                );
              ?>
            </p>
            <input id="h5p_network_toggle_input" name="h5p_network_enabled" style="display: none;" type="checkbox" value="true"<?php if ($enabled) : ?> checked="checked"<?php endif; ?>/>
            <button id="h5p_network_toggle_button" type="button" class="button">
                <?php
                echo esc_html(
                    $enabled
                      ? __('Disable', 'h5p')
                      : __('Enable', 'h5p')
                );
                ?>
            </button>
          </td>
        </tr>
      </tbody>
    </table>
    <?php wp_nonce_field('h5p_network_settings', 'save_network_settings'); ?>
    <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"></p>
  </form>
</div>
