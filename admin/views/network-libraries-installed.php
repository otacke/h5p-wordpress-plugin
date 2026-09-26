<?php
/**
 * Grid of the libraries installed on the network (WAI-ARIA APG data grid pattern).
 *
 * Expects $overview from H5P_Network_Admin::get_library_overview().
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<?php if (empty($overview['installed'])): ?>
  <?php esc_html_e('No libraries installed.', 'h5p'); ?>
<?php else: ?>
  <div class="h5p-network-libraries-grid h5p-network-libraries-grid--installed" role="grid" aria-labelledby="h5p-network-libraries-installed-heading">
    <div class="h5p-network-libraries-row" role="row">
      <div class="h5p-network-libraries-cell" role="columnheader"><?php esc_html_e('Library', 'h5p'); ?></div>
      <div class="h5p-network-libraries-cell" role="columnheader"><?php esc_html_e('Version', 'h5p'); ?></div>
      <?php // The columns that hold buttons have no visible titles, but screen readers announce them. ?>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Information', 'h5p'); ?></span>
      </div>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Update', 'h5p'); ?></span>
      </div>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Upgrade contents', 'h5p'); ?></span>
      </div>
      <div class="h5p-network-libraries-cell" role="columnheader">
        <span class="screen-reader-text"><?php esc_html_e('Delete', 'h5p'); ?></span>
      </div>
    </div>

    <?php foreach ($overview['installed'] as $library): ?>
      <?php
      $name = $this->format_library_name($library);
      $version = $this->format_library_version($library);
      // Non-runnable libraries (dependencies) can neither be updated nor have contents upgraded.
      $runnable = !empty($library['runnable']);
      $is_minor_or_major = $runnable && !empty($library['update']['isMinorOrMajor']);
      // Bold alone is not announced, so the Update button label carries the hint.
      $bold_name = $is_minor_or_major;
      ?>
      <div class="h5p-network-libraries-row" role="row" data-library="<?php print esc_attr($library['machineName']); ?>">
        <?php include __DIR__ . '/network-libraries-library-cell.php'; ?>
        <div class="h5p-network-libraries-cell" role="gridcell"><?php print esc_html($version); ?></div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          $button = array(
            'icon' => 'info-outline',
            'label' => sprintf(__('Information about %1$s', 'h5p'), $name),
            'disabled' => TRUE,
          );
          include __DIR__ . '/network-libraries-icon-button.php';
          ?>
        </div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          if ($runnable && $overview['hubIsEnabled'] && !empty($library['update'])) {
            $target = $this->format_library_version($library['update']);
            $button = array(
              'icon' => 'database-export',
              'label' => $is_minor_or_major
                ? sprintf(__('Update %1$s to %2$s (new major or minor version)', 'h5p'), $name, $target)
                : sprintf(__('Update %1$s to %2$s', 'h5p'), $name, $target),
              'data' => array(
                'h5p-library-action' => 'update',
                'machine-name' => $library['machineName'],
                'confirm-message' => $is_minor_or_major
                  ? sprintf(__('Update %1$s from %2$s to %3$s? After the update, upgrade the existing contents created with this library.', 'h5p'), $name, $version, $target)
                  : sprintf(__('Update %1$s from %2$s to %3$s?', 'h5p'), $name, $version, $target),
                'success-message' => sprintf(__('%1$s was updated.', 'h5p'), $name),
              ),
            );
            include __DIR__ . '/network-libraries-icon-button.php';
          }
          elseif ($runnable) {
            $button = array(
              'icon' => 'database-export',
              'label' => sprintf(__('Update %1$s', 'h5p'), $name),
              'disabled' => TRUE,
            );
            include __DIR__ . '/network-libraries-icon-button.php';
          }
          ?>
        </div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          if ($runnable) {
            $button = array(
              'icon' => 'controls-skipforward',
              'label' => sprintf(__('Upgrade contents using %1$s', 'h5p'), $name),
              'disabled' => TRUE,
            );
            include __DIR__ . '/network-libraries-icon-button.php';
          }
          ?>
        </div>

        <div class="h5p-network-libraries-cell" role="gridcell">
          <?php
          $button = array(
            'icon' => 'database-remove',
            'label' => sprintf(__('Delete %1$s', 'h5p'), $name),
            'disabled' => TRUE,
          );
          include __DIR__ . '/network-libraries-icon-button.php';
          ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
