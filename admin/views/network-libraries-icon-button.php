<?php
/**
 * Square icon button of a library grid.
 *
 * Unavailable actions use aria-disabled, not disabled, so they stay reachable
 * in roving-tabindex navigation and announce themselves.
 *
 * Expects $button with 'icon' (dashicon name without prefix), 'label', and
 * optionally 'disabled' and 'data' (data attributes without the data- prefix).
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */

$disabled = !empty($button['disabled']);
?>
<button
  type="button"
  class="button h5p-icon-button<?php print $disabled ? ' h5p-icon-button-disabled' : ''; ?>"
  aria-label="<?php print esc_attr($button['label']); ?>"
  <?php if ($disabled): ?>
    aria-disabled="true"
    title="<?php esc_attr_e('Not available yet', 'h5p'); ?>"
  <?php endif; ?>
  <?php foreach ((isset($button['data']) ? $button['data'] : array()) as $key => $value): ?>
    data-<?php print esc_attr($key); ?>="<?php print esc_attr($value); ?>"
  <?php endforeach; ?>
>
  <span class="dashicons dashicons-<?php print esc_attr($button['icon']); ?>" aria-hidden="true"></span>
</button>
