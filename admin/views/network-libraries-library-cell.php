<?php
/**
 * Row header cell of library grid (icon, name); expects $library (row), $name (formatted) and $bold_name.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
?>
<div class="h5p-network-libraries-cell" role="rowheader">
  <?php if (!empty($library['icon'])): ?>
    <img class="h5p-network-libraries-icon" src="<?php print esc_url($library['icon']); ?>" width="54" height="32" alt="">
  <?php else: ?>
    <?php // Placeholder keeps the name column aligned for libraries without an icon. ?>
    <div class="h5p-network-libraries-icon"></div>
  <?php endif; ?>
  <span class="h5p-network-libraries-name">
    <?php if ($bold_name): ?>
      <strong><?php print esc_html($name); ?></strong>
    <?php else: ?>
      <?php print esc_html($name); ?>
    <?php endif; ?>
  </span>
</div>
