<?php
/**
 * Network level H5P restrictions.
 *
 * Which runnable library versions are restricted on which blogs, in two views: one library version with a
 * paged list of blogs, and a paged list of blogs with all runnable library versions of the chosen one.
 *
 * Expects $model from H5P_Network_Library_Restrictions::get_page_model().
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.19.0
 */

$page_url = network_admin_url('settings.php?page=h5p_restrictions');
$tab_url = add_query_arg('tab', $model['tab'], $page_url);
$total_pages = (int) ceil($model['matchedSites'] / H5P_Network_Library_Restrictions::SITES_PER_PAGE);

$pagination = paginate_links(array(
  'base'    => add_query_arg('paged', '%#%'),
  'format'  => '',
  'current' => $model['paged'],
  'total'   => $total_pages,
));

// Group versions under their content type, as the hub shows one content type per machine name.
$content_types = array();
foreach ($model['libraries'] as $library) {
  $content_types[$library->name]['title'] = $library->title;
  $content_types[$library->name]['versions'][] = $library;
}

$format_version = function ($library) {
  return "{$library->major_version}.{$library->minor_version}.{$library->patch_version}";
};
?>

<div class="wrap h5p-network-restrictions">
  <h1><?php print esc_html(get_admin_page_title()); ?></h1>
  <p class="description">
    <?php esc_html_e('Authors on a site cannot create content with a library version restricted on that site. Network administrators are never restricted.', 'h5p'); ?>
  </p>
  <div class="h5p-network-restrictions-notices" aria-live="polite"></div>

  <nav class="nav-tab-wrapper">
    <?php
    $tabs = array(
      H5P_Network_Library_Restrictions::TAB_LIBRARIES => __('By content type', 'h5p'),
      H5P_Network_Library_Restrictions::TAB_SITES => __('By site', 'h5p'),
    );
    ?>
    <?php foreach ($tabs as $tab => $tab_label): ?>
      <?php if ($tab === $model['tab']): ?>
        <a href="<?php print esc_url(add_query_arg('tab', $tab, $page_url)); ?>" class="nav-tab nav-tab-active" aria-current="page"><?php print esc_html($tab_label); ?></a>
      <?php else: ?>
        <a href="<?php print esc_url(add_query_arg('tab', $tab, $page_url)); ?>" class="nav-tab"><?php print esc_html($tab_label); ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <?php if (empty($model['libraries'])): ?>
    <p><?php esc_html_e('No runnable libraries installed.', 'h5p'); ?></p>

  <?php elseif ($model['tab'] === H5P_Network_Library_Restrictions::TAB_LIBRARIES): ?>
    <div class="h5p-network-restrictions-layout">
      <div class="h5p-network-restrictions-master">
        <h2><?php esc_html_e('Content types', 'h5p'); ?></h2>
        <ul class="h5p-network-restrictions-list">
          <?php foreach ($content_types as $machine_name => $content_type): ?>
            <li>
              <span class="h5p-network-restrictions-type"><?php print esc_html($content_type['title']); ?></span>
              <span class="h5p-network-restrictions-machine-name"><?php print esc_html($machine_name); ?></span>
              <ul>
                <?php foreach ($content_type['versions'] as $library): ?>
                  <?php $is_current = $model['library'] !== null && (int) $model['library']->id === (int) $library->id; ?>
                  <li>
                    <a href="<?php print esc_url(add_query_arg('library', (int) $library->id, $tab_url)); ?>"<?php if ($is_current): ?> class="current" aria-current="true"<?php endif; ?>>
                      <?php print esc_html($format_version($library)); ?>
                    </a>
                    <span class="h5p-network-restrictions-count<?php if ($library->restricted_blogs > 0): ?> h5p-network-restrictions-count--restricted<?php endif; ?>">
                      <?php
                      printf(
                        /* translators: 1: number of sites the library version is restricted on, 2: number of all sites */
                        esc_html__('restricted on %1$d of %2$d sites', 'h5p'),
                        (int) $library->restricted_blogs,
                        (int) $model['totalSites']
                      );
                      ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="h5p-network-restrictions-detail">
        <?php if ($model['library'] !== null): ?>
          <?php $library = $model['library']; ?>
          <h2>
            <?php print esc_html("{$library->title} {$format_version($library)}"); ?>
          </h2>

          <form method="get" action="<?php print esc_url(network_admin_url('settings.php')); ?>" class="search-form">
            <input type="hidden" name="page" value="h5p_restrictions"/>
            <input type="hidden" name="tab" value="<?php print esc_attr($model['tab']); ?>"/>
            <input type="hidden" name="library" value="<?php print (int) $library->id; ?>"/>
            <p class="search-box">
              <label class="screen-reader-text" for="h5p-restrictions-site-search"><?php esc_html_e('Search sites by address', 'h5p'); ?></label>
              <input type="search" id="h5p-restrictions-site-search" name="s" value="<?php print esc_attr($model['search']); ?>"
                     placeholder="<?php esc_attr_e('Search sites by address', 'h5p'); ?>"/>
              <input type="submit" class="button" value="<?php esc_attr_e('Search sites', 'h5p'); ?>"/>
            </p>
          </form>

          <div class="h5p-network-restrictions-bulk">
            <?php
            $scope = $model['search'] === ''
              /* translators: %d: number of sites */
              ? sprintf(_n('all %d site', 'all %d sites', $model['matchedSites'], 'h5p'), $model['matchedSites'])
              /* translators: %d: number of sites */
              : sprintf(_n('the %d matching site', 'all %d matching sites', $model['matchedSites'], 'h5p'), $model['matchedSites']);
            $bulk_actions = array(
              '1' => array(
                /* translators: %s: "all 12 sites" or "all 3 matching sites" */
                'label'   => sprintf(__('Restrict on %s', 'h5p'), $scope),
                /* translators: 1: content type and version, 2: "all 12 sites" or "all 3 matching sites" */
                'confirm' => sprintf(__('Restrict %1$s on %2$s?', 'h5p'), "{$library->title} {$format_version($library)}", $scope),
              ),
              '0' => array(
                /* translators: %s: "all 12 sites" or "all 3 matching sites" */
                'label'   => sprintf(__('Allow on %s', 'h5p'), $scope),
                /* translators: 1: content type and version, 2: "all 12 sites" or "all 3 matching sites" */
                'confirm' => sprintf(__('Allow %1$s on %2$s?', 'h5p'), "{$library->title} {$format_version($library)}", $scope),
              ),
            );
            ?>
            <?php foreach ($bulk_actions as $restricted => $bulk_action): ?>
              <button type="button" class="button" data-h5p-restrictions-apply="<?php print esc_attr($restricted); ?>"
                      data-library="<?php print (int) $library->id; ?>"
                      data-search="<?php print esc_attr($model['search']); ?>"
                      data-confirm-message="<?php print esc_attr($bulk_action['confirm']); ?>"
                      data-confirm-label="<?php print esc_attr($bulk_action['label']); ?>"
                      <?php disabled($model['matchedSites'], 0); ?>>
                <?php print esc_html($bulk_action['label']); ?>
              </button>
            <?php endforeach; ?>
          </div>

          <?php if (empty($model['sites'])): ?>
            <p><?php esc_html_e('No sites found.', 'h5p'); ?></p>
          <?php else: ?>
            <table class="wp-list-table widefat striped h5p-network-restrictions-table">
              <thead>
                <tr>
                  <td class="check-column">
                    <label class="screen-reader-text" for="h5p-restrictions-toggle-all"><?php esc_html_e('Restrict on all sites of this page', 'h5p'); ?></label>
                    <input type="checkbox" id="h5p-restrictions-toggle-all" data-h5p-restrictions-toggle-all/>
                  </td>
                  <th scope="col"><?php esc_html_e('Restricted on site', 'h5p'); ?></th>
                  <th scope="col"><?php esc_html_e('Address', 'h5p'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($model['sites'] as $site): ?>
                  <?php $checkbox_id = "h5p-restriction-{$library->id}-{$site['id']}"; ?>
                  <tr>
                    <th scope="row" class="check-column">
                      <input type="checkbox" id="<?php print esc_attr($checkbox_id); ?>" data-h5p-restriction="<?php print esc_attr("{$library->id}:{$site['id']}"); ?>"
                             <?php checked($site['restricted']); ?>/>
                    </th>
                    <td><label for="<?php print esc_attr($checkbox_id); ?>"><?php print esc_html($site['name']); ?></label></td>
                    <td><?php print esc_html($site['address']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <div class="tablenav bottom">
            <?php if (!empty($model['sites'])): ?>
              <button type="button" class="button button-primary" data-h5p-restrictions-save>
                <?php esc_html_e('Save changes', 'h5p'); ?>
              </button>
            <?php endif; ?>
            <?php if ($pagination): ?>
              <div class="tablenav-pages"><?php print $pagination; ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <div class="h5p-network-restrictions-layout">
      <div class="h5p-network-restrictions-master">
        <h2><?php esc_html_e('Sites', 'h5p'); ?></h2>

        <form method="get" action="<?php print esc_url(network_admin_url('settings.php')); ?>" class="search-form">
          <input type="hidden" name="page" value="h5p_restrictions"/>
          <input type="hidden" name="tab" value="<?php print esc_attr($model['tab']); ?>"/>
          <p class="search-box">
            <label class="screen-reader-text" for="h5p-restrictions-site-search"><?php esc_html_e('Search sites by address', 'h5p'); ?></label>
            <input type="search" id="h5p-restrictions-site-search" name="s" value="<?php print esc_attr($model['search']); ?>"
                   placeholder="<?php esc_attr_e('Search sites by address', 'h5p'); ?>"/>
            <input type="submit" class="button" value="<?php esc_attr_e('Search sites', 'h5p'); ?>"/>
          </p>
        </form>

        <?php if (empty($model['sites'])): ?>
          <p><?php esc_html_e('No sites found.', 'h5p'); ?></p>
        <?php else: ?>
          <ul class="h5p-network-restrictions-list">
            <?php foreach ($model['sites'] as $site): ?>
              <?php $is_current = $model['site'] !== null && $model['site']['id'] === $site['id']; ?>
              <li>
                <a href="<?php print esc_url(add_query_arg('blog', $site['id'])); ?>"<?php if ($is_current): ?> class="current" aria-current="true"<?php endif; ?>>
                  <?php print esc_html($site['name']); ?>
                </a>
                <span class="h5p-network-restrictions-machine-name"><?php print esc_html($site['address']); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($pagination): ?>
          <div class="tablenav"><div class="tablenav-pages"><?php print $pagination; ?></div></div>
        <?php endif; ?>
      </div>

      <div class="h5p-network-restrictions-detail">
        <?php if ($model['site'] === null): ?>
          <p><?php esc_html_e('Choose a site to see its restrictions.', 'h5p'); ?></p>
        <?php else: ?>
          <?php $site = $model['site']; ?>
          <h2><?php print esc_html($site['name']); ?></h2>
          <p class="h5p-network-restrictions-machine-name"><?php print esc_html($site['address']); ?></p>

          <table class="wp-list-table widefat striped h5p-network-restrictions-table">
            <thead>
              <tr>
                <td class="check-column">
                  <label class="screen-reader-text" for="h5p-restrictions-toggle-all"><?php esc_html_e('Restrict all content types on this site', 'h5p'); ?></label>
                  <input type="checkbox" id="h5p-restrictions-toggle-all" data-h5p-restrictions-toggle-all/>
                </td>
                <th scope="col"><?php esc_html_e('Restricted content type', 'h5p'); ?></th>
                <th scope="col"><?php esc_html_e('Version', 'h5p'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($content_types as $machine_name => $content_type): ?>
                <?php foreach ($content_type['versions'] as $library): ?>
                  <?php $checkbox_id = "h5p-restriction-{$library->id}-{$site['id']}"; ?>
                  <tr>
                    <th scope="row" class="check-column">
                      <input type="checkbox" id="<?php print esc_attr($checkbox_id); ?>" data-h5p-restriction="<?php print esc_attr("{$library->id}:{$site['id']}"); ?>"
                             <?php checked(isset($site['restrictedLibraries'][(int) $library->id])); ?>/>
                    </th>
                    <td>
                      <label for="<?php print esc_attr($checkbox_id); ?>"><?php print esc_html($content_type['title']); ?></label>
                      <span class="h5p-network-restrictions-machine-name"><?php print esc_html($machine_name); ?></span>
                    </td>
                    <td><?php print esc_html($format_version($library)); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="tablenav bottom">
            <button type="button" class="button button-primary" data-h5p-restrictions-save>
              <?php esc_html_e('Save changes', 'h5p'); ?>
            </button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
