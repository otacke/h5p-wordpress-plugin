<?php
/**
 * Network level H5P management.
 *
 * Shows the H5P Hub client so a network admin can install and update content
 * types that are shared by every blog.
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
  <div id="post-body-content">
    <div class="h5p-hub-management"><?php esc_html_e('Waiting for javascript...', 'h5p'); ?></div>
  </div>
</div>
