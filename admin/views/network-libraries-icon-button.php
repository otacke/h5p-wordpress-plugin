<?php
/**
 * Square icon button of library grid; aria-disabled, not disabled, keeps it reachable in roving-tabindex navigation.
 *
 * Expects $button: 'icon' (dashicon name w/o prefix), 'label', optional 'disabled', 'data' (w/o data- prefix).
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */

$disabled = !empty($button['disabled']);
?>
<button
  type="button"
  class="button h5p-icon-button<?php print $disabled ? ' h5p-icon-button-disabled' : ''; ?>"
  aria-label="<?php print esc_attr($button['label']); ?>"
  title="<?php print esc_attr($button['label']); ?>"
  <?php if ($disabled): ?>
    aria-disabled="true"
  <?php endif; ?>
  <?php foreach ((isset($button['data']) ? $button['data'] : array()) as $key => $value): ?>
    data-<?php print esc_attr($key); ?>="<?php print esc_attr($value); ?>"
  <?php endforeach; ?>
>
  <span class="dashicons dashicons-<?php print esc_attr($button['icon']); ?>" aria-hidden="true"></span>
</button>
