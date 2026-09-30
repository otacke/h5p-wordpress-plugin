<?php
/**
 * Cache tools above the grids of the network management page, as on the Libraries page (views/libraries.php).
 *
 * The buttons run through the page script (AJAX). The section is left out if none of its boxes applies.
 *
 * Expects $overview from H5P_Network_Library_Overview::get_library_overview(), $content_type_cache_updated_at
 * (timestamp, 0 if never) and $not_cached (number of contents on all blogs without a cache).
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */
?>

<?php if ($overview['hubIsEnabled'] || $not_cached > 0): ?>
<div class="h5p-network-libraries-tools" data-h5p-tools="caches">
  <div class="h5p-network-libraries-tools-notices" aria-live="polite"></div>

  <?php if ($overview['hubIsEnabled']): ?>
    <h2><?php esc_html_e('Content Type Cache', 'h5p'); ?></h2>
    <div class="h5p postbox">
      <div class="h5p-text-holder">
        <p><?php esc_html_e('Making sure the content type cache is up to date will ensure that you can view, download and use the latest libraries. This is different from updating the libraries themselves.', 'h5p'); ?></p>
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row"><?php esc_html_e('Last update', 'h5p'); ?></th>
              <td>
                <?php
                print esc_html($content_type_cache_updated_at > 0
                  ? date_i18n('l, F j, Y H:i:s', $content_type_cache_updated_at)
                  : __('never', 'h5p'));
                ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="h5p-button-holder">
        <span class="h5p-network-libraries-tool-action">
          <button type="button" class="button button-primary button-large" data-h5p-library-action="update-content-type-cache">
            <?php esc_html_e('Update', 'h5p'); ?>
          </button>
        </span>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($not_cached > 0): ?>
    <h2><?php esc_html_e('Rebuild cache', 'h5p'); ?></h2>
    <div class="h5p postbox">
      <div class="h5p-text-holder">
        <p><?php esc_html_e('Not all content has gotten their cache rebuilt. This is required to be able to delete libraries, and to display how many contents that uses the library.', 'h5p'); ?></p>
        <p class="h5p-network-libraries-rebuild-progress" role="status" aria-live="polite">
          <?php
          print esc_html(sprintf(
            _n('1 content need to get its cache rebuilt.', '%d contents needs to get their cache rebuilt.', $not_cached, 'h5p'),
            $not_cached
          ));
          ?>
        </p>
      </div>
      <div class="h5p-button-holder">
        <span class="h5p-network-libraries-tool-action">
          <button type="button" class="button button-primary button-large" data-h5p-library-action="rebuild-cache">
            <?php esc_html_e('Rebuild cache', 'h5p'); ?>
          </button>
        </span>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
