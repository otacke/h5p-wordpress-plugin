<?php
/**
 * Grid of the hub content types that are not installed yet (WAI-ARIA APG data grid pattern).
 *
 * Expects $overview from H5P_Network_Admin::get_library_overview().
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<?php if (!$overview['hubIsEnabled']): ?>
  <?php esc_html_e('The H5P Hub is disabled. Enable it in the H5P settings to install content types.', 'h5p'); ?>
<?php elseif (empty($overview['available'])): ?>
  <?php esc_html_e('All available content types are installed.', 'h5p'); ?>
<?php else: ?>
  <div class="h5p-network-libraries-grid h5p-network-libraries-grid--available" role="grid" aria-labelledby="h5p-network-libraries-available-heading">
    <div class="h5p-network-libraries-row" role="row">
      <div class="h5p-network-libraries-cell" role="columnheader"><?php esc_html_e('Library', 'h5p'); ?></div>
      <div class="h5p-network-libraries-cell" role="columnheader"><?php esc_html_e('Version', 'h5p'); ?></div>
      <?php // The columns that hold buttons have no visible titles, but screen readers announce them. ?>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Information', 'h5p'); ?></span>
      </div>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Install', 'h5p'); ?></span>
      </div>
    </div>

    <?php foreach ($overview['available'] as $library): ?>
      <?php
      $name = $this->format_library_name($library);
      $bold_name = FALSE;
      ?>
      <div class="h5p-network-libraries-row" role="row" data-library="<?php print esc_attr($library['machineName']); ?>">
        <?php include __DIR__ . '/network-libraries-library-cell.php'; ?>
        <div class="h5p-network-libraries-cell" role="gridcell"><?php print esc_html($this->format_library_version($library)); ?></div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          $button = array(
            'icon' => 'info-outline',
            'label' => sprintf(__('Information about %1$s', 'h5p'), $name),
            'data' => array(
              'h5p-library-action' => 'info',
              'info-message-html' => $library['infoMessageHtml'],
            ),
          );
          include __DIR__ . '/network-libraries-icon-button.php';
          ?>
        </div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          $button = array(
            'icon' => 'database-add',
            'label' => sprintf(__('Install %1$s', 'h5p'), $name),
            'disabled' => empty($library['canInstall']),
          );
          if (!empty($library['canInstall'])) {
            $button['data'] = array(
              'h5p-library-action' => 'install',
              'machine-name' => $library['machineName'],
              'success-message' => sprintf(__('%1$s was installed.', 'h5p'), $name),
            );
          }
          include __DIR__ . '/network-libraries-icon-button.php';
          ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
