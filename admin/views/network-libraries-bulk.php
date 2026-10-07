<?php
/**
 * Bulk actions above the grids of the network management page.
 *
 * Each button runs all the libraries its action applies to in one go, and reloads once at the end
 * with a single summary (page script, AJAX). A button with no eligible library is disabled. The
 * counts use the same conditions as the row buttons of the installed and available grids, and the
 * section is left out if no button applies.
 *
 * Expects $overview from H5P_Network_Library_Overview::get_library_overview().
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<?php
// The counts come with the overview, under the same conditions as the row buttons of the grids.
$updates = $overview['bulkCounts']['updates'];
$installs = $overview['bulkCounts']['installs'];
$deletions = $overview['bulkCounts']['deletions'];
$upgrades = $overview['bulkCounts']['upgrades'];
$bulk_actions = array(
  array(
    'count' => $updates,
    'action' => 'update-all',
    'label' => __('Update all (%d)', 'h5p'),
    'confirmLabel' => __('Update', 'h5p'),
  ),
  array(
    'count' => $installs,
    'action' => 'install-all',
    'label' => __('Install all (%d)', 'h5p'),
    'confirmLabel' => __('Install', 'h5p'),
  ),
  array(
    'count' => $deletions,
    'action' => 'delete-all',
    'label' => __('Delete all (%d)', 'h5p'),
    'confirmLabel' => __('Delete', 'h5p'),
  ),
  array(
    'count' => $upgrades,
    'action' => 'upgrade-all',
    'label' => __('Upgrade all contents (%d)', 'h5p'),
    'confirmLabel' => __('Upgrade', 'h5p'),
  ),
);
?>

<?php if ($updates + $installs + $deletions + $upgrades > 0): ?>
<div class="h5p-network-libraries-tools" data-h5p-tools="bulk">
  <div class="h5p-network-libraries-tools-notices" aria-live="polite"></div>

  <h2><?php esc_html_e('Bulk actions', 'h5p'); ?></h2>
  <div class="h5p postbox">
    <div class="h5p-button-holder h5p-network-libraries-bulk-actions">
      <?php foreach ($bulk_actions as $bulk_action): ?>
        <span class="h5p-network-libraries-tool-action">
          <button type="button" class="button button-primary button-large"
            <?php if ($bulk_action['count'] > 0): ?>
              data-h5p-library-action="<?php print esc_attr($bulk_action['action']); ?>"
              data-count="<?php print esc_attr($bulk_action['count']); ?>"
              data-confirm-label="<?php print esc_attr($bulk_action['confirmLabel']); ?>"
            <?php else: ?>
              aria-disabled="true"
            <?php endif; ?>>
            <?php print esc_html(sprintf($bulk_action['label'], $bulk_action['count'])); ?>
          </button>
        </span>
      <?php endforeach; ?>
    </div>
    <p class="h5p-network-libraries-bulk-progress" role="status" aria-live="polite"></p>
  </div>
</div>
<?php endif; ?>
