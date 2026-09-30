<?php
/**
 * Network level H5P management.
 *
 * A library management interface built from the network library and hub
 * cache tables: the libraries installed on the network, and the available
 * content types that can be installed and updated.
 *
 * Expects $overview from H5P_Network_Library_Overview::get_library_overview().
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<div class="wrap">
  <h2><?php print esc_html(get_admin_page_title()); ?></h2>
  <?php H5P_Plugin_Admin::print_messages(); ?>

  <div class="h5p-network-libraries">
    <?php include __DIR__ . '/network-libraries-tools.php'; ?>
    <?php include __DIR__ . '/network-libraries-bulk.php'; ?>

    <h2 id="h5p-network-libraries-installed-heading">
      <?php esc_html_e('Installed libraries', 'h5p'); ?>
    </h2>
    <div class="h5p-network-libraries-notices" aria-live="polite"></div>
    <div class="h5p-network-libraries-installed">
      <?php include __DIR__ . '/network-libraries-installed.php'; ?>
    </div>

    <h2 id="h5p-network-libraries-available-heading">
      <?php esc_html_e('Available libraries', 'h5p'); ?>
    </h2>
    <div class="h5p-network-libraries-available">
      <?php include __DIR__ . '/network-libraries-available.php'; ?>
    </div>

    <?php include __DIR__ . '/network-libraries-upload.php'; ?>
  </div>
</div>
