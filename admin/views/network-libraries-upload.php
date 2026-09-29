<?php
/**
 * Library upload below the grids of the network management page, as on the Libraries page (views/libraries.php).
 *
 * The button runs through the page script (AJAX), so the form is never submitted natively.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<div class="h5p-network-libraries-tools" data-h5p-tools="upload">
  <div class="h5p-network-libraries-tools-notices" aria-live="polite"></div>

  <h2><?php esc_html_e('Upload Libraries', 'h5p'); ?></h2>
  <form class="h5p-network-libraries-upload" enctype="multipart/form-data">
    <div class="h5p postbox">
      <div class="h5p-text-holder">
        <p><?php esc_html_e('Here you can upload new libraries or upload updates to existing libraries. Files uploaded here must be in the .h5p file format.', 'h5p'); ?></p>
        <input type="file" name="h5p_file" id="h5p-network-libraries-file" accept=".h5p"/>
        <input type="checkbox" name="h5p_upgrade_only" id="h5p-network-libraries-upgrade-only"/>
        <label for="h5p-network-libraries-upgrade-only"><?php esc_html_e('Only update existing libraries', 'h5p'); ?></label>
        <?php if (current_user_can('disable_h5p_security')): ?>
          <div class="h5p-disable-file-check">
            <label>
              <input type="checkbox" name="h5p_disable_file_check" id="h5p-network-libraries-disable-file-check"/>
              <?php esc_html_e('Disable file extension check', 'h5p'); ?>
            </label>
            <div class="h5p-warning"><?php esc_html_e("Warning! This may have security implications as it allows for uploading php files. That in turn could make it possible for attackers to execute malicious code on your site. Please make sure you know exactly what you're uploading.", 'h5p'); ?></div>
          </div>
        <?php endif; ?>
      </div>
      <div class="h5p-button-holder">
        <span class="h5p-network-libraries-tool-action">
          <button type="button" class="button button-primary button-large" data-h5p-library-action="upload">
            <?php esc_html_e('Upload', 'h5p'); ?>
          </button>
        </span>
      </div>
    </div>
  </form>
</div>
