<?php

/**
 * H5P_Network_Admin
 *
 * Super-admin (Network Admin) UI for enabling/disabling H5P "Network Mode" — central, network-level
 * library management. Lives under Network Admin > Settings and is only registered on multisite.
 * Delegates migration logic to H5P_Network_Migrate_To_Network and H5P_Network_Migrate_To_Local.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Admin {

  /**
   * Sub content reference results by name and major.minor, cached while delete_deletable_libraries() runs.
   *
   * Null outside that loop, so has_subcontent_references() queries fresh for the single-row delete.
   *
   * @var array|null
   */
  private $subcontent_references = null;

  public function __construct() {
    add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
    add_action('wp_ajax_h5p_migrate_to_network', array($this, 'handle_migrate_to_network'));
    add_action('wp_ajax_h5p_migrate_to_local', array($this, 'handle_migrate_to_local'));
    add_action('wp_ajax_h5p_network_library_delete', array($this, 'handle_delete_library'));
    add_action('wp_ajax_h5p_network_library_delete_all', array($this, 'handle_delete_all_libraries'));
    add_action('wp_ajax_h5p_network_library_install', array($this, 'handle_library_install'));
    add_action('wp_ajax_h5p_network_library_upload', array($this, 'handle_library_upload'));
    add_action('wp_ajax_h5p_network_update_content_type_cache', array($this, 'handle_update_content_type_cache'));
    add_action('wp_ajax_h5p_network_rebuild_cache', array($this, 'handle_rebuild_cache'));
  }

  /**
   * Add the "H5P Network" page under the network Settings menu.
   */
  public function add_network_admin_menu() {
    add_submenu_page(
      'settings.php',
      __('H5P Network', 'h5p'),
      __('H5P Network', 'h5p'),
      'manage_network',
      'h5p_network',
      array($this, 'render_settings_page')
    );

    if (H5PCommons::is_network_enabled()) {
      // Installing and updating content types writes to network level libraries, so offered to network admins only.
      // Registered without a parent, so it has no menu entry of its own and opens at admin.php?page=h5p_management.
      $management_page = add_submenu_page(
        '',
        __('H5P Management', 'h5p'),
        __('H5P Management', 'h5p'),
        'manage_network',
        'h5p_management',
        array($this, 'render_management_page')
      );
      add_action('load-' . $management_page, array($this, 'set_management_page_title'));
      // The page stays registered and loadable, but without this, get_admin_page_parent() would find it under
      // the empty parent and reset the parent_file set by highlight_management_menu_entry(), closing Settings.
      remove_submenu_page('', 'h5p_management');

      // The Settings entry links to the management page; a slug with a query is used as the link as it is.
      add_submenu_page(
        'settings.php',
        __('H5P Network Libraries', 'h5p'),
        __('H5P Network Libraries', 'h5p'),
        'manage_network',
        'admin.php?page=h5p_management'
      );

      add_filter('parent_file', array($this, 'highlight_management_menu_entry'));
      add_filter('submenu_file', array($this, 'highlight_management_submenu_entry'));
    }
  }

  /**
   * Set the title of the management page, which get_admin_page_title() cannot find for a page without a parent.
   */
  public function set_management_page_title() {
    global $title;

    $title = __('H5P Management', 'h5p');
  }

  /**
   * Open the Settings menu on the management page, as the page has no menu entry of its own.
   *
   * @param string $parent_file Parent menu file of the current page.
   *
   * @return string
   */
  public function highlight_management_menu_entry($parent_file) {
    global $plugin_page;

    return $plugin_page === 'h5p_management' ? 'settings.php' : $parent_file;
  }

  /**
   * Mark the Settings > H5P network libraries entry as current on the management page.
   *
   * @param string|null $submenu_file Submenu file of the current page.
   *
   * @return string|null
   */
  public function highlight_management_submenu_entry($submenu_file) {
    global $plugin_page;

    return $plugin_page === 'h5p_management' ? 'admin.php?page=h5p_management' : $submenu_file;
  }

  /**
   * Render the network level H5P management page.
   */
  public function render_management_page() {
    if (!current_user_can('manage_network')) {
      wp_die(esc_html__('You do not have permission to manage H5P content types.', 'h5p'));
    }

    // Set up H5PIntegration and enqueue the editor scripts, so H5PEditor.getAjaxUrl() is available.
    $content = new H5PContentAdmin('h5p');
    $content->add_editor_assets();

    $plugin = H5P_Plugin::get_instance();
    $library_settings = array(
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('h5p_network_ajax'),
      'upgrade' => $this->get_upgrade_settings(),
      'l10n' => $this->get_library_l10n(),
    );
    $plugin->print_settings($library_settings, 'H5PNetworkLibraries');

    $overview = $this->get_library_overview();
    $interface = $plugin->get_h5p_instance('interface');
    // Read after the overview, which may have refreshed the content type cache.
    $content_type_cache_updated_at = (int) $interface->getOption('content_type_cache_updated_at', 0);
    // Contents on all blogs whose cache is missing; the rebuild box is shown only while there are any.
    $not_cached = (int) $interface->getNumNotFiltered();
    include 'views/network-management.php';

    H5P_Plugin_Admin::add_script('h5p-jquery', 'h5p-php-library/js/jquery.js');
    wp_enqueue_script(
      $plugin->asset_handle('plugin-confirmation-dialog'),
      plugins_url('h5p/admin/scripts/h5p-confirmation-dialog.js'),
      array(),
      H5P_Plugin::VERSION
    );
    wp_enqueue_style(
      $plugin->asset_handle('plugin-confirmation-dialog'),
      plugins_url('h5p/admin/styles/h5p-confirmation-dialog.css'),
      array(),
      H5P_Plugin::VERSION
    );
    wp_enqueue_script(
      $plugin->asset_handle('network-libraries'),
      plugins_url('h5p/admin/scripts/h5p-network-libraries.js'),
      array($plugin->asset_handle('editor')),
      H5P_Plugin::VERSION
    );
    wp_enqueue_style(
      $plugin->asset_handle('network-libraries'),
      plugins_url('h5p/admin/styles/h5p-network-libraries.css'),
      array(),
      H5P_Plugin::VERSION
    );
  }

  /**
   * Collect the installed and available libraries for the network management page.
   *
   * @return array
   */
  private function get_library_overview() {
    $plugin = H5P_Plugin::get_instance();
    $core = $plugin->get_h5p_instance('core');
    $interface = $plugin->get_h5p_instance('interface');

    $hub_is_enabled = get_option('h5p_hub_is_enabled', TRUE) == TRUE;

    // Keep the hub cache fresh, like H5PEditorAjax::isContentTypeCacheUpdated() does.
    if ($hub_is_enabled && $interface->getOption('content_type_cache_updated_at', 0) + 60 * 60 * 24 * 7 < time()) {
      try {
        $core->updateContentTypeCache();
      }
      catch (Exception $exception) {
        // Not fatal: the existing cache is used instead.
        error_log('H5P network management: ' . $exception->getMessage());
      }
    }

    $hub = array();
    foreach ((array) $this->get_hub_cache() as $cached) {
      if (!isset($hub[$cached->machine_name])
          || $this->compare_library_versions($cached, $hub[$cached->machine_name]) > 0) {
        $hub[$cached->machine_name] = $cached;
      }
    }

    // Content counts per library version across all blogs, for the upgrade and delete actions.
    $content_counts = $this->get_content_counts();

    $metadata = $this->load_library_metadata();
    $installed = array();
    $installed_names = array();
    foreach ($interface->loadLibraries() as $name => $versions) {
      $installed_names[$name] = TRUE;

      // loadLibraries() does not sort, so find the newest installed version explicitly.
      $newest = $versions[0];
      foreach ($versions as $version) {
        if ($this->compare_library_versions($version, $newest) > 0) {
          $newest = $version;
        }
      }

      foreach ($versions as $version) {
        $installed[] = array(
          'id' => (int) $version->id,
          'machineName' => $version->name,
          'title' => $version->title,
          'majorVersion' => (int) $version->major_version,
          'minorVersion' => (int) $version->minor_version,
          'patchVersion' => (int) $version->patch_version,
          'runnable' => (bool) $version->runnable,
          'icon' => $this->get_library_icon($version, $metadata, $hub, $interface),
          // Only the newest installed version of a library can offer an update.
          'update' => ($version === $newest) ? $this->get_available_update($version, $hub) : NULL,
          // Contents that use this version as their main library, across all blogs.
          'contentCount' => isset($content_counts[(int) $version->id])
            ? (int) $content_counts[(int) $version->id]
            : 0,
          // The newest installed version of the same major.minor line, if newer than this row.
          'upgradeTarget' => $this->get_upgrade_target($version, $newest),
        );
      }
    }

    // Deletability of a row depends on every other row, so decide it in a second pass.
    $deletion_model = array(
      'libraries' => array(),
      'contentCounts' => $content_counts,
      'dependencies' => $this->get_dependency_indexes(),
    );
    foreach ($installed as $row) {
      $deletion_model['libraries'][$row['id']] = array(
        'id' => $row['id'],
        'name' => $row['machineName'],
        'majorVersion' => $row['majorVersion'],
        'minorVersion' => $row['minorVersion'],
        'addTo' => isset($metadata[$row['id']]) ? $metadata[$row['id']]['addTo'] : '',
      );
    }
    foreach ($installed as $index => $row) {
      $deletion = $this->is_library_deletable(
        $deletion_model['libraries'][$row['id']],
        $deletion_model
      );
      $installed[$index]['deletable'] = $deletion['deletable'];
      $installed[$index]['alsoDelete'] = $deletion['alsoDelete'];
      $installed[$index]['infoMessageHtml'] = $this->build_library_info_html(
        $row,
        isset($hub[$row['machineName']]) ? $hub[$row['machineName']] : null,
        $row['contentCount'],
        $this->count_preloaded_or_editor_dependents($row['id'], $deletion_model['dependencies']),
        $this->get_library_json_info($row)
      );
    }

    $available = array();
    foreach ($hub as $machine_name => $cached) {
      if (isset($installed_names[$machine_name])) {
        continue;
      }
      $row = array(
        'machineName' => $cached->machine_name,
        'title' => $cached->title,
        'icon' => !empty($cached->icon) ? $cached->icon : null,
        'majorVersion' => (int) $cached->major_version,
        'minorVersion' => (int) $cached->minor_version,
        'patchVersion' => (int) $cached->patch_version,
        'canInstall' => $this->is_hub_library_compatible($cached)
          && $this->can_install_hub_library($cached),
      );
      // Not installed: hub data only, no usage statistics.
      $row['infoMessageHtml'] = $this->build_library_info_html($row, $cached, null, null);
      $available[] = $row;
    }

    usort($installed, array($this, 'sort_libraries'));
    usort($available, array($this, 'sort_libraries'));

    return array(
      'installed' => $installed,
      'available' => $available,
      'hubIsEnabled' => $hub_is_enabled,
    );
  }

  /**
   * @return array
   */
  private function get_hub_cache() {
    return (array) (new H5PEditorWordPressAjax())->getContentTypeCache();
  }

  /**
   * Count the contents that use each library version as their main library, across all blogs.
   *
   * @return array Map of library id to content count.
   */
  private function get_content_counts() {
    $counts = array();

    H5PCommons::for_each_blog(function () use (&$counts) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet.
      }

      $rows = $wpdb->get_results(
        "SELECT library_id, COUNT(id) AS content_count
          FROM {$table_contents}
          GROUP BY library_id"
      );

      foreach ($rows as $row) {
        $id = (int) $row->library_id;
        $counts[$id] = (isset($counts[$id]) ? $counts[$id] : 0) + (int) $row->content_count;
      }
    });

    return $counts;
  }

  /**
   * Determine whether given database table exists on the current blog.
   *
   * @param string $table Full table name.
   *
   * @return bool
   */
  private function table_exists($table) {
    global $wpdb;

    return $wpdb->get_var(
      $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    ) === $table;
  }

  /**
   * Index the network library dependency table by both ends of each edge.
   *
   * Self-referential rows (a library that lists itself) are ignored completely: they
   * do not count as a dependency and do not block deletion.
   *
   * @return array With 'dependents', a map of required library id to the library ids
   *               that require it, and 'types', a map of "library_id:required_library_id"
   *               to the dependency type.
   */
  private function get_dependency_indexes() {
    global $wpdb;

    $table = H5PCommons::build_full_db_table_name('h5p_libraries_libraries');

    $indexes = array(
      'dependents' => array(),
      'types' => array(),
    );
    foreach ((array) $wpdb->get_results(
      "SELECT library_id, required_library_id, dependency_type FROM {$table}"
    ) as $row) {
      if ((int) $row->library_id === (int) $row->required_library_id) {
        continue; // Self-referential dependencies do not count at all.
      }

      $indexes['dependents'][(int) $row->required_library_id][] = (int) $row->library_id;
      $indexes['types'][(int) $row->library_id . ':' . (int) $row->required_library_id] = $row->dependency_type;
    }

    return $indexes;
  }

  /**
   * Collect what the delete action needs to decide with: every installed version,
   * the content counts across all blogs, and the indexed dependencies.
   *
   * @param array|null $content_counts From get_content_counts(); reloaded when null.
   *
   * @return array
   */
  private function get_deletion_model($content_counts = null) {
    global $wpdb;

    $table_libraries = H5PCommons::build_full_db_table_name('h5p_libraries');

    $libraries = array();
    foreach ((array) $wpdb->get_results(
      "SELECT id, name, major_version, minor_version, patch_version, add_to FROM {$table_libraries}"
    ) as $row) {
      $libraries[(int) $row->id] = array(
        'id' => (int) $row->id,
        'name' => $row->name,
        'majorVersion' => (int) $row->major_version,
        'minorVersion' => (int) $row->minor_version,
        'patchVersion' => (int) $row->patch_version,
        'addTo' => (string) $row->add_to,
      );
    }

    return array(
      'libraries' => $libraries,
      'contentCounts' => $content_counts === null ? $this->get_content_counts() : $content_counts,
      'dependencies' => $this->get_dependency_indexes(),
    );
  }

  /**
   * The dependency type from one library version to another, if there is one.
   *
   * @param int $library_id
   * @param int $required_library_id
   * @param array $model
   *
   * @return string|null
   */
  private function dependency_type($library_id, $required_library_id, $model) {
    $key = (int) $library_id . ':' . (int) $required_library_id;

    return isset($model['dependencies']['types'][$key]) ? $model['dependencies']['types'][$key] : null;
  }

  /**
   * Whether a library version has dependents other than one allowed one.
   *
   * @param int $library_id
   * @param int $allowed_dependent
   * @param array $model
   *
   * @return bool
   */
  private function has_dependents_beyond($library_id, $allowed_dependent, $model) {
    if (!isset($model['dependencies']['dependents'][$library_id])) {
      return false;
    }

    foreach ($model['dependencies']['dependents'][$library_id] as $dependent) {
      if ((int) $dependent !== (int) $allowed_dependent) {
        return true;
      }
    }

    return false;
  }

  /**
   * Count the library versions that require $id as a preloaded or editor
   * dependency, for the Info message.
   *
   * The dependency table has one row per pair, so each dependent is counted
   * once. Self-referential rows are already filtered out of $indexes.
   *
   * @param int $id
   * @param array $indexes From get_dependency_indexes().
   *
   * @return int
   */
  private function count_preloaded_or_editor_dependents($id, $indexes) {
    $count = 0;

    if (isset($indexes['dependents'][$id])) {
      foreach ($indexes['dependents'][$id] as $dependent) {
        $type = isset($indexes['types'][$dependent . ':' . $id]) ? $indexes['types'][$dependent . ':' . $id] : '';
        if ($type === 'preloaded' || $type === 'editor') {
          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * @return array
   */
  private function deletion_blocked() {
    return array('deletable' => false, 'alsoDelete' => null);
  }

  /**
   * Decide whether a library version can be deleted, and whether the circular editor
   * dependency must go with it.
   *
   * A version is deletable when no content on any blog uses it as its main library,
   * no content embeds it as sub content, and no other library depends on it (a
   * library that lists itself does not count). Addons
   * (non-empty add_to) are exempt from all of this: their only reference is their
   * add_to target, which keeps working without the addon.
   *
   * The one exception to the dependency rule is a circular pair: when the library
   * lists another library in its editorDependencies and that library lists this one
   * in its preloadedDependencies, both are deleted together — but only when nothing
   * else needs either of them, so deleting the pair leaves no broken dependency rows.
   *
   * @param array $library Version to check (id, name, majorVersion, minorVersion, addTo).
   * @param array $model From get_deletion_model().
   *
   * @return array With 'deletable' and 'alsoDelete' (id of the circular partner, or null).
   */
  private function is_library_deletable($library, $model) {
    if (!empty($library['addTo'])) {
      // Addons can always be deleted.
      return array('deletable' => true, 'alsoDelete' => null);
    }

    $id = (int) $library['id'];
    $dependents = isset($model['dependencies']['dependents'][$id])
      ? $model['dependencies']['dependents'][$id]
      : array();

    if (empty($dependents)) {
      if (!empty($model['contentCounts'][$id])) {
        return $this->deletion_blocked();
      }
      if ($this->has_subcontent_references(
          $library['name'],
          (int) $library['majorVersion'],
          (int) $library['minorVersion']
        )
      ) {
        return $this->deletion_blocked();
      }

      return array('deletable' => true, 'alsoDelete' => null);
    }

    foreach ($dependents as $partner_id) {
      $partner_id = (int) $partner_id;

      // The exception only applies to the library whose editorDependencies list the partner.
      if ($this->dependency_type($id, $partner_id, $model) !== 'editor'
          || $this->dependency_type($partner_id, $id, $model) !== 'preloaded') {
        continue;
      }

      $partner = isset($model['libraries'][$partner_id]) ? $model['libraries'][$partner_id] : null;
      if ($partner === null) {
        continue;
      }

      // Nothing else may need either of the two, and no content may use the partner.
      // The checked version is deleted as well, so content embedding it as sub
      // content would break, too.
      if ($this->has_dependents_beyond($id, $partner_id, $model)
          || $this->has_dependents_beyond($partner_id, $id, $model)
          || !empty($model['contentCounts'][$partner_id])
          || $this->has_subcontent_references(
            $library['name'],
            (int) $library['majorVersion'],
            (int) $library['minorVersion']
          )
          || $this->has_subcontent_references(
            $partner['name'],
            (int) $partner['majorVersion'],
            (int) $partner['minorVersion']
          )
      ) {
        continue;
      }

      return array('deletable' => true, 'alsoDelete' => $partner_id);
    }

    return $this->deletion_blocked();
  }

  /**
   * Build the REGEXP pattern that matches a library version as a sub content
   * reference in the parameters of a content.
   *
   * Sub content entries in the parameters look like {"library": "Name-1.1"}. The
   * pattern tolerates a space before the version, as found in uploaded content.json
   * files, and matches that exact version only: content embedding 1.2 is not
   * affected by deleting 1.1.
   *
   * Whitespace around the colon is matched with an explicit tab/space class
   * instead of [[:space:]] because the REGEXP engine of older MariaDB versions
   * does not support POSIX character classes and would silently fail to match
   * the whole pattern.
   *
   * @param string $name Machine name.
   * @param int $major
   * @param int $minor
   *
   * @return string The pattern, without delimiters.
   */
  private function subcontent_reference_pattern($name, $major, $minor) {
    $escaped = preg_quote($name, '/');
    $whitespace = '[ \t]*';
    return '"library"' . $whitespace . ':' . $whitespace . '"' . $escaped . '[ -]' . $major . '\.' . $minor . '"';
  }

  /**
   * Whether any blog's content embeds a library version as sub content.
   *
   * @param string $name Machine name.
   * @param int $major
   * @param int $minor
   *
   * @return bool
   */
  private function has_subcontent_references($name, $major, $minor) {
    // Content parameters do not change while delete_deletable_libraries() runs, so neither can the answer.
    $key = $name . ' ' . $major . '.' . $minor;
    if ($this->subcontent_references !== null && isset($this->subcontent_references[$key])) {
      return $this->subcontent_references[$key];
    }

    $pattern = $this->subcontent_reference_pattern($name, $major, $minor);

    $found = false;
    H5PCommons::for_each_blog(function () use ($pattern, &$found) {
      if ($found) {
        return; // One hit on any blog is enough.
      }

      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');

      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables yet.
      }

      $found = null !== $wpdb->get_var(
        $wpdb->prepare(
          "SELECT 1
            FROM {$table_contents}
            WHERE parameters REGEXP %s
            LIMIT 1",
          $pattern
        )
      );
    });

    if ($this->subcontent_references !== null) {
      $this->subcontent_references[$key] = $found;
    }

    return $found;
  }

  /**
   * Find the newest installed version of a library that has a newer major.minor than the given row.
   *
   * @param object $library A row of the installed libraries.
   * @param object $newest The newest installed version of the same machine name.
   *
   * @return array|null
   */
  private function get_upgrade_target($library, $newest) {
    if ((int) $newest->major_version === (int) $library->major_version
        && (int) $newest->minor_version === (int) $library->minor_version) {
      return NULL;
    }

    return array(
      'id' => (int) $newest->id,
      'majorVersion' => (int) $newest->major_version,
      'minorVersion' => (int) $newest->minor_version,
      'patchVersion' => (int) $newest->patch_version,
    );
  }

  /**
   * Settings for the content upgrade, following H5PLibraryAdmin::display_content_upgrades().
   *
   * @return array
   */
  private function get_upgrade_settings() {
    return array(
      'libraryBaseUrl' => admin_url('admin-ajax.php?action=h5p_content_upgrade_library&library='),
      'progressUrl' => admin_url('admin-ajax.php?action=h5p_content_upgrade_progress&id='),
      'scriptBaseUrl' => plugins_url('h5p/h5p-php-library/js'),
      'buster' => '?ver=' . H5P_Plugin::VERSION,
      'token' => wp_create_nonce('h5p_content_upgrade'),
    );
  }

  /**
   * loadLibraries() does not return has_icon or add_to, so the values are loaded separately.
   *
   * @return array Map of library id to an array with 'hasIcon' and 'addTo'.
   */
  private function load_library_metadata() {
    global $wpdb;

    $table = H5PCommons::build_full_db_table_name('h5p_libraries');
    $metadata = array();
    foreach ((array) $wpdb->get_results("SELECT id, has_icon, add_to FROM {$table}") as $row) {
      $metadata[(int) $row->id] = array(
        'hasIcon' => (bool) $row->has_icon,
        'addTo' => (string) $row->add_to,
      );
    }
    return $metadata;
  }

  /**
   * Get the icon of an installed library: local icon, hub icon or none.
   *
   * @param object $library
   * @param array $metadata
   * @param array $hub
   * @param object $interface
   *
   * @return string|null
   */
  private function get_library_icon($library, $metadata, $hub, $interface) {
    if (!empty($metadata[(int) $library->id]['hasIcon'])) {
      $folder = H5PCore::libraryToFolderName(array(
        'machineName' => $library->name,
        'majorVersion' => (int) $library->major_version,
        'minorVersion' => (int) $library->minor_version,
        'patchVersion' => (int) $library->patch_version,
        'patchVersionInFolderName' => FALSE,
      ));
      return $interface->getLibraryFileUrl($folder, 'icon.svg');
    }

    if (isset($hub[$library->name]) && !empty($hub[$library->name]->icon)) {
      return $hub[$library->name]->icon;
    }

    return null;
  }

  /**
   * Find a newer, compatible and installable hub version of an installed library.
   *
   * @param object $library
   * @param array $hub
   *
   * @return array|null
   */
  private function get_available_update($library, $hub) {
    if (!isset($hub[$library->name])) {
      return NULL;
    }

    $cached = $hub[$library->name];
    if ($this->compare_library_versions($cached, $library) <= 0
        || !$this->is_hub_library_compatible($cached)
        || !$this->can_install_hub_library($cached)) {
      return NULL;
    }

    return array(
      'majorVersion' => (int) $cached->major_version,
      'minorVersion' => (int) $cached->minor_version,
      'patchVersion' => (int) $cached->patch_version,
      'isMinorOrMajor' => (int) $cached->major_version !== (int) $library->major_version
        || (int) $cached->minor_version !== (int) $library->minor_version,
    );
  }

  /**
   * Check that a hub library is compatible with the installed H5P core API version.
   *
   * @param object $cached
   *
   * @return bool
   */
  private function is_hub_library_compatible($cached) {
    $required_major = (int) $cached->h5p_major_version;
    $required_minor = (int) $cached->h5p_minor_version;
    return $required_major < H5PCore::$coreApi['majorVersion']
      || ($required_major === H5PCore::$coreApi['majorVersion']
          && $required_minor <= H5PCore::$coreApi['minorVersion']);
  }

  /**
   * Check that the current user may install a hub library, like H5peditor::canInstallContentType().
   *
   * @param object $cached
   *
   * @return bool
   */
  private function can_install_hub_library($cached) {
    return H5PCommons::current_user_can_manage_libraries()
      || ((bool) $cached->is_recommended && H5PCommons::current_user_can_install_recommended_libraries());
  }

  /**
   * Compare two library versions.
   *
   * @param object $a
   * @param object $b
   *
   * @return int
   */
  private function compare_library_versions($a, $b) {
    foreach (array('major_version', 'minor_version', 'patch_version') as $component) {
      $a_value = (int) $a->{$component};
      $b_value = (int) $b->{$component};
      if ($a_value !== $b_value) {
        return $a_value < $b_value ? -1 : 1;
      }
    }
    return 0;
  }

  /**
   * Sort library rows by title, then by version.
   *
   * @param array $a
   * @param array $b
   *
   * @return int
   */
  private static function sort_libraries($a, $b) {
    $title = strcasecmp($a['title'], $b['title']);
    if ($title !== 0) {
      return $title;
    }
    $a_version = $a['majorVersion'] . '.' . $a['minorVersion'] . '.' . $a['patchVersion'];
    $b_version = $b['majorVersion'] . '.' . $b['minorVersion'] . '.' . $b['patchVersion'];
    return version_compare($a_version, $b_version);
  }

  /**
   * Format the version of a library row as major.minor.patch.
   *
   * @param array $library
   *
   * @return string
   */
  private function format_library_version($library) {
    return $library['majorVersion'] . '.' . $library['minorVersion'] . '.' . $library['patchVersion'];
  }

  /**
   * Format the name of a library row as title (machine name).
   *
   * @param array $library
   *
   * @return string
   */
  private function format_library_name($library) {
    return sprintf('%s (%s)', $library['title'], $library['machineName']);
  }

  /**
   * Read the author, description and license from the library.json of an
   * installed library, used as a fallback when the H5P Hub has no data for it.
   *
   * @param array $row Installed library row.
   *
   * @return array|null The fields that are set, each a non-empty string, keyed
   *               by author, description and license; null when there is no
   *               readable library.json.
   */
  private function get_library_json_info($row) {
    $path = H5PCommons::get_h5p_network_path() . '/libraries/'
      . $row['machineName'] . '-' . $row['majorVersion'] . '.' . $row['minorVersion']
      . '/library.json';

    $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($data)) {
      return null;
    }

    $info = array();
    foreach (array('author', 'description', 'license') as $field) {
      if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
        $info[$field] = $data[$field];
      }
    }
    return $info;
  }

  /**
   * Build the HTML message the Info action of a library row shows.
   *
   * The dialog renders it with innerHTML. Every field value is escaped and the
   * whole document is reduced to a restricted wp_kses whitelist, so it holds
   * no markup beyond the structural tags used here: a highlighted title, a
   * qualifier/value table and an unordered list of usage statistics. Rows and
   * list items with missing data are left out.
   *
   * The H5P Hub is the preferred source of metadata; for installed libraries
   * the values from their library.json fill in whatever the Hub has no data for.
   *
   * @param array $row Library row of either grid.
   * @param object|null $hub_row Newest hub cache row of the library, if any;
   *               null for installed libraries without a hub entry, e.g.
   *               non-runnable dependencies.
   * @param int|null $content_count Contents using this installed version as main
   *               library, across all blogs; null when the library is not installed.
   * @param int|null $dependent_count Installed versions that require it as a
   *               preloaded or editor dependency; null when the library is not
   *               installed.
   * @param array|null $file_info author, description and license of the
   *               library's library.json for installed libraries; null for
   *               available ones.
   *
   * @return string
   */
  private function build_library_info_html($row, $hub_row, $content_count, $dependent_count, $file_info = null) {
    $file_info = is_array($file_info) ? $file_info : array();

    $hub_owner = $hub_row !== null && !empty($hub_row->owner) ? $hub_row->owner : null;

    $hub_description = null;
    if ($hub_row !== null) {
      $hub_description = !empty($hub_row->description)
        ? $hub_row->description
        : (!empty($hub_row->summary) ? $hub_row->summary : null);
    }

    $hub_license = null;
    if ($hub_row !== null && !empty($hub_row->license)) {
      $license = json_decode($hub_row->license, true);
      if (is_array($license) && !empty($license['id'])) {
        $hub_license = $license['id'];
      }
    }

    // Qualifier/value table; rows with no value are left out.
    $fields = array(
      array(__('Version', 'h5p'), $this->format_library_version($row)),
      array(__('Machine name', 'h5p'), $row['machineName']),
      array(
        __('Maintainer', 'h5p'),
        $hub_owner !== null ? $hub_owner : (isset($file_info['author']) ? $file_info['author'] : null)
      ),
      array(
        __('Description', 'h5p'),
        $hub_description !== null ? $hub_description : (isset($file_info['description']) ? $file_info['description'] : null)
      ),
      array(
        __('License', 'h5p'),
        $hub_license !== null ? $hub_license : (isset($file_info['license']) ? $file_info['license'] : null)
      )
    );

    $table = '<table>';
    foreach ($fields as $field) {
      list($label, $value) = $field;
      if ($value === null || $value === '') {
        continue;
      }
      $table .= '<tr>'
        . '<th scope="row">' . esc_html($label) . '</th>'
        . '<td>' . esc_html($value) . '</td>'
        . '</tr>';
    }
    $table .= '</table>';

    $html = '<h2>' . esc_html($row['title']) . '</h2>' . $table;

    if ($content_count !== null) {
      // Installed rows only; available rows pass null for all counts.
      $html .= '<ul>'
        . '<li>' . esc_html(sprintf(
          _n('Number of contents using this library as main library: %d', 'Number of contents using this library as main library: %d', $content_count, 'h5p'),
          $content_count
        )) . '</li>'
        . '<li>' . esc_html(sprintf(
          _n('Number of libraries using this library as preloaded or editor dependency: %d', 'Number of libraries using this library as preloaded or editor dependency: %d', $dependent_count, 'h5p'),
          $dependent_count
        )) . '</li>'
        . '</ul>';
    }

    // Restricted whitelist: only the structural tags used above, nothing else.
    return wp_kses($html, array(
      'h2' => array(),
      'table' => array(),
      'tr' => array(),
      'th' => array('scope' => array()),
      'td' => array(),
      'ul' => array(),
      'li' => array()
    ));
  }

  /**
   * Get the strings the library grid script needs; the grids themselves are rendered server-side.
   *
   * @return array
   */
  private function get_library_l10n() {
    return array(
      'cancel' => __('Cancel', 'h5p'),
      'close' => __('Close', 'h5p'),
      'confirm' => __('Update', 'h5p'),
      'requestFailed' => __('The library could not be installed or updated. Please try again.', 'h5p'),
      'deleteFailed' => __('The library could not be deleted. Please try again.', 'h5p'),
      'working' => __('Working...', 'h5p'),
      'dismiss' => __('Dismiss this notice.', 'h5p'),
      'uploadFailed' => __('The library package could not be uploaded. Please try again.', 'h5p'),
      // Same msgid as the upload error in handle_library_upload(), so the two stay in sync.
      'noFile' => __('No file was uploaded', 'h5p'),
      'contentTypeCacheFailed' => __('The content type cache could not be updated. Please try again.', 'h5p'),
      'rebuildFailed' => __('The content cache could not be rebuilt. Please try again.', 'h5p'),
      'rebuildDone' => __('The content cache was rebuilt.', 'h5p'),
      // The msgids of the Libraries page (H5PLibraryAdmin::get_not_cached_settings()), so the translations stay
      // in sync. The singular template contains no %d; the JS picks the template by count.
      'notCachedSingular' => _n('1 content need to get its cache rebuilt.', '%d contents needs to get their cache rebuilt.', 1, 'h5p'),
      'notCachedPlural' => _n('1 content need to get its cache rebuilt.', '%d contents needs to get their cache rebuilt.', 2, 'h5p'),
      // The msgids of the upgrade error messages are the same as in H5PLibraryAdmin::display_content_upgrades(),
      // so the translations of the two pages stay in sync.
      'inProgress' => __('Upgrading to %ver...', 'h5p'),
      'error' => __('An error occurred while processing parameters:', 'h5p'),
      'errorData' => __('Could not load data for library %lib.', 'h5p'),
      'errorContent' => __('Could not upgrade content %id:', 'h5p'),
      'errorScript' => __('Could not load upgrades script for %lib.', 'h5p'),
      'errorParamsBroken' => __('Parameters are broken.', 'h5p'),
      'errorLibrary' => __('Missing required library %lib.', 'h5p'),
      'errorTooHighVersion' => __('Parameters contain %used while only %supported or earlier are supported.', 'h5p'),
      'errorNotSupported' => __('Parameters contain %used which is not supported.', 'h5p'),
      // The singular templates contain no %d; the JS picks the template by count.
      'upgradedSingular' => _n('1 content upgraded', '%d contents upgraded', 1, 'h5p'),
      'upgradedPlural' => _n('1 content upgraded', '%d contents upgraded', 2, 'h5p'),
      'failedSingular' => _n('1 content could not be upgraded', '%d contents could not be upgraded', 1, 'h5p'),
      'failedPlural' => _n('1 content could not be upgraded', '%d contents could not be upgraded', 2, 'h5p'),
      // The singular templates contain no %d; the JS picks the template by count.
      'bulkConfirmUpdateSingular' => __('Update 1 library?', 'h5p'),
      'bulkConfirmUpdatePlural' => __('Update %d libraries?', 'h5p'),
      'bulkConfirmInstallSingular' => __('Install 1 content type?', 'h5p'),
      'bulkConfirmInstallPlural' => __('Install %d content types?', 'h5p'),
      'bulkConfirmDeleteSingular' => __('Delete 1 library, and any that become deletable afterwards?', 'h5p'),
      'bulkConfirmDeletePlural' => __('Delete %d libraries, and any that become deletable afterwards?', 'h5p'),
      'bulkConfirmUpgradeSingular' => __('Upgrade the contents of 1 library to a newer version?', 'h5p'),
      'bulkConfirmUpgradePlural' => __('Upgrade the contents of %d libraries to newer versions?', 'h5p'),
      'bulkProgressUpdate' => __('Updating %lib (%i of %n)...', 'h5p'),
      'bulkProgressInstall' => __('Installing %lib (%i of %n)...', 'h5p'),
      'bulkProgressDelete' => __('Deleting... %d deleted', 'h5p'),
      'bulkProgressUpgrade' => __('Upgrading %lib %old to %new (%i of %n)...', 'h5p'),
      'bulkUpdatedSingular' => _n('1 library updated', '%d libraries updated', 1, 'h5p'),
      'bulkUpdatedPlural' => _n('1 library updated', '%d libraries updated', 2, 'h5p'),
      'bulkInstalledSingular' => _n('1 content type installed', '%d content types installed', 1, 'h5p'),
      'bulkInstalledPlural' => _n('1 content type installed', '%d content types installed', 2, 'h5p'),
      'bulkDeletedSingular' => _n('1 library deleted:', '%d libraries deleted:', 1, 'h5p'),
      'bulkDeletedPlural' => _n('1 library deleted:', '%d libraries deleted:', 2, 'h5p'),
      'bulkSkippedSingular' => _n('1 skipped (already up to date)', '%d skipped (already up to date)', 1, 'h5p'),
      'bulkSkippedPlural' => _n('1 skipped (already up to date)', '%d skipped (already up to date)', 2, 'h5p'),
      'bulkUpdateFailedSingular' => _n('1 library could not be updated:', '%d libraries could not be updated:', 1, 'h5p'),
      'bulkUpdateFailedPlural' => _n('1 library could not be updated:', '%d libraries could not be updated:', 2, 'h5p'),
      'bulkInstallFailedSingular' => _n('1 content type could not be installed:', '%d content types could not be installed:', 1, 'h5p'),
      'bulkInstallFailedPlural' => _n('1 content type could not be installed:', '%d content types could not be installed:', 2, 'h5p'),
      'bulkFailed' => __('One of the requests failed, so the bulk action was stopped.', 'h5p'),
    );
  }

  /**
   * Render network settings page.
   */
  public function render_settings_page() {
    $save = filter_input( INPUT_POST, 'save_network_settings', FILTER_SANITIZE_SPECIAL_CHARS );

    if ($save !== null) {
      check_admin_referer( 'h5p_network_settings', 'save_network_settings' );
    }

    // Read back the stored state, so the form always shows what is in effect.
    $enabled = H5PCommons::is_network_enabled();

    include 'views/network-settings.php';
  }

  /**
   * Handle AJAX request to migrate libraries to network level.
   */
  public function handle_migrate_to_network() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network')) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    // Migrates copies and deletes every library file of every blog, which can
    // take longer than the configured limit. Not honoured by every host.
    @set_time_limit(0);
    ignore_user_abort(true);

    $migrate = new H5P_Network_Migrate_To_Network();

    $state_before = H5P_Network_Migrate_To_Network::getState();
    $phase = $state_before['phase'];

    try {
      $progress = $migrate->migrateNextBatch();
    }
    catch (Exception $exception) {
      $rolled_back = false;

      if (H5P_Network_Migrate_To_Network::isPhaseRollbackPossible($phase)) {
        try {
          $demigrate = new H5P_Network_Migrate_To_Local();
          $demigrate->deleteNetworkFilesDirectory();
          $demigrate->dropNetworkTables();
          H5P_Network_Migrate_To_Network::clearState();
          $rolled_back = true;
        }
        catch (Exception $rollback_exception) {
          error_log('H5P network migration rollback: ' . $rollback_exception->getMessage());
        }
      }

      error_log(
        sprintf(
          'H5P network migration failed in phase %s (step %s): %s',
          $phase,
          $migrate->getFailedStep(),
          $exception->getMessage()
        )
      );

      wp_send_json_error(
        array(
          'message'    => $exception->getMessage(),
          'step'       => $migrate->getFailedStep(),
          'phase'      => $phase,
          'rolledBack' => $rolled_back,
        ),
        H5PCommons::HTTP_OK
      );

      return;
    }

    if (!$progress['done']) {
      // More blogs to work through, so client calls again.
      wp_send_json_success(
        array(
          'phase'      => $progress['phase'],
          'percentage' => $progress['percentage'],
          'done'       => false,
          'nonce'      => wp_create_nonce('h5p_network_ajax'),
        )
      );
      return;
    }

    $this->set_network_mode(true);

    if (!(defined('H5P_DISABLE_AGGREGATION') && H5P_DISABLE_AGGREGATION === true)) {
      try {
        $migrate->createCachedAssets();
      }
      catch (Exception $exception) {
        // Not fatal: cached assets are created lazily when content is viewed.
        error_log('H5P network migration: ' . $exception->getMessage());
      }
    }

    wp_send_json_success(
      array(
        'message' => __('Migrated libraries to network level.', 'h5p'),
        'done'    => true,
      )
    );
  }

  /**
   * Handle AJAX request to migrate libraries back to local (blog) level.
   */
  public function handle_migrate_to_local() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network')) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    // Migrating copies and deletes every library file of every blog, which can
    // take longer than the configured limit. Not honoured by every host.
    @set_time_limit(0);
    ignore_user_abort(true);

    $demigrate = new H5P_Network_Migrate_To_Local();
    $demigrate->migrateToLocal();

    $this->set_network_mode(false);

    if (!(defined('H5P_DISABLE_AGGREGATION') && H5P_DISABLE_AGGREGATION === true)) {
      try {
        $demigrate->createCachedAssets();
      }
      catch (Exception $exception) {
        // Not fatal: cached assets are created lazily when content is viewed.
        error_log('H5P network demigration: ' . $exception->getMessage());
      }
    }

    wp_send_json_success();
  }

  /**
   * Handle AJAX request to delete an installed library version.
   */
  public function handle_delete_library() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network') || !H5PCommons::current_user_can_manage_libraries()) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    // The grid may be stale, so recheck deletability with fresh data.
    $model = $this->get_deletion_model();
    $library = isset($model['libraries'][$id]) ? $model['libraries'][$id] : null;
    if ($library === null) {
      wp_send_json_error(
        array('message' => __('This library is no longer installed.', 'h5p')),
        404
      );
    }

    $deletion = $this->is_library_deletable($library, $model);
    if (!$deletion['deletable']) {
      // Same message as the blog level library admin, so the translations stay in sync.
      wp_send_json_error(
        array(
          'message' => __(
            'This Library is used by content or other libraries and can therefore not be deleted.',
            'h5p'
          ),
        )
      );
    }

    $this->delete_library_version($id, $deletion['alsoDelete']);

    wp_send_json_success();
  }

  /**
   * Delete a library version, and the circular partner that must go with it.
   *
   * @param int $id
   * @param int|null $also_delete Id of the circular partner, from is_library_deletable().
   */
  private function delete_library_version($id, $also_delete) {
    global $wpdb;

    $plugin = H5P_Plugin::get_instance();
    $interface = $plugin->get_h5p_instance('interface');
    $core = $plugin->get_h5p_instance('core');

    $to_delete = array($id);
    if ($also_delete !== null) {
      $to_delete[] = $also_delete;
    }

    $table_libraries = H5PCommons::build_full_db_table_name('h5p_libraries');
    foreach ($to_delete as $library_id) {
      // deleteLibrary() leaves the cached assets of the version behind,
      // like H5PCore::saveLibraries() does when replacing a library.
      if ($core->aggregateAssets && ($hashes = $interface->deleteCachedAssets($library_id))) {
        $core->fs->deleteCachedAssets($hashes);
      }

      $to_be_deleted = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_libraries} WHERE id = %d", $library_id)
      );
      if ($to_be_deleted !== null) {
        $interface->deleteLibrary($to_be_deleted);
      }
    }
  }

  /**
   * Delete every deletable library version, and those that become deletable by that, until the time is up.
   *
   * Works in passes. Each pass rebuilds the libraries and dependencies of the model and deletes what
   * is_library_deletable() passes, addons included. Within a pass the model still lists the versions
   * deleted in it as dependents, so it can only block more, never allow more; the versions they free
   * are deleted in the next pass. Passes repeat while one deletes something and there is time left.
   *
   * Can also be called outside an AJAX request, e.g. by a scheduled task.
   *
   * @param float $deadline microtime(TRUE) after which no new pass is started.
   * @param bool $dry_run Only record what would be deleted, for testing the passes on real data.
   *
   * @return array With 'deleted', the names and versions deleted, and 'more', whether time ran out
   *               while the last pass still deleted something.
   */
  public function delete_deletable_libraries($deadline, $dry_run = false) {
    // Deleting libraries does not change which contents use them.
    $content_counts = $this->get_content_counts();

    $deleted = array();
    $deleted_ids = array();
    $more = false;

    $this->subcontent_references = array();
    try {
      do {
        $model = $this->get_deletion_model($content_counts);

        // A dry run deletes nothing, so leave out what it would have deleted, like the database would.
        foreach (array_keys($deleted_ids) as $deleted_id) {
          unset($model['libraries'][$deleted_id], $model['dependencies']['dependents'][$deleted_id]);
        }
        foreach ($model['dependencies']['dependents'] as $required_id => $dependents) {
          $model['dependencies']['dependents'][$required_id] = array_values(array_filter(
            $dependents,
            function ($dependent) use ($deleted_ids) {
              return !isset($deleted_ids[(int) $dependent]);
            }
          ));
        }

        $deleted_in_pass = 0;
        foreach ($model['libraries'] as $id => $library) {
          if (isset($deleted_ids[$id])) {
            continue; // Deleted as the circular partner of an earlier version in this pass.
          }

          $deletion = $this->is_library_deletable($library, $model);
          if (!$deletion['deletable']) {
            continue;
          }

          if (!$dry_run) {
            $this->delete_library_version($id, $deletion['alsoDelete']);
          }

          $versions = array($id);
          if ($deletion['alsoDelete'] !== null) {
            $versions[] = (int) $deletion['alsoDelete'];
          }
          foreach ($versions as $version_id) {
            if (isset($model['libraries'][$version_id])) {
              $version = $model['libraries'][$version_id];
              $deleted[] = $version['name'] . ' ' . $version['majorVersion'] . '.' . $version['minorVersion']
                . '.' . $version['patchVersion'];
            }
            $deleted_ids[$version_id] = TRUE;
            $deleted_in_pass++;
          }
        }

        $time_left = microtime(TRUE) < $deadline;
      } while ($deleted_in_pass > 0 && $time_left);

      $more = $deleted_in_pass > 0 && !$time_left;
    }
    finally {
      $this->subcontent_references = null;
    }

    return array(
      'deleted' => $deleted,
      'more' => $more,
    );
  }

  /**
   * Handle AJAX request to delete every deletable library version, one time budget at a time.
   *
   * Answers 'more' while the page should repeat the request.
   */
  public function handle_delete_all_libraries() {
    $this->verify_library_management_request();

    wp_send_json_success(
      $this->delete_deletable_libraries(microtime(TRUE) + H5PLibraryAdmin::UPGRADE_BATCH_TIMEOUT)
    );
  }

  /**
   * Handle AJAX request to install or update libraries from an uploaded .h5p package.
   *
   * Works like the upload on the Libraries page (H5PLibraryAdmin::process_libraries()) and shares its
   * H5P_Plugin_Admin::handle_upload(), which also honours the "Disable file extension check" option.
   */
  public function handle_library_upload() {
    $this->verify_library_management_request();

    $error = isset($_FILES['h5p_file']) ? (int) $_FILES['h5p_file']['error'] : UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
      // Same messages as H5PLibraryAdmin::process_libraries(), so the translations stay in sync.
      $upload_errors = array(
        UPLOAD_ERR_INI_SIZE => __('The uploaded file exceeds the upload_max_filesize directive in php.ini', 'h5p'),
        UPLOAD_ERR_FORM_SIZE => __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form', 'h5p'),
        UPLOAD_ERR_PARTIAL => __('The uploaded file was only partially uploaded', 'h5p'),
        UPLOAD_ERR_NO_FILE => __('No file was uploaded', 'h5p'),
        UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder', 'h5p'),
        UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'h5p'),
        UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'h5p'),
      );

      wp_send_json_error(array(
        'messages' => array(
          'info' => array(),
          'error' => array(
            isset($upload_errors[$error])
              ? $upload_errors[$error]
              : __('The library package could not be uploaded. Please try again.', 'h5p')
          ),
        ),
      ));
    }

    $result = H5P_Plugin_Admin::get_instance()->handle_upload(
      NULL,
      filter_input(INPUT_POST, 'h5p_upgrade_only') ? TRUE : FALSE
    );

    $messages = $this->collect_h5p_messages();
    if ($result === FALSE || !empty($messages['error'])) {
      wp_send_json_error(array('messages' => $messages));
    }

    // Core reports only added or updated libraries, so without this the notice after the reload would be empty.
    if (empty($messages['info'])) {
      $messages['info'][] = __('The package was valid, but no libraries were added or updated.', 'h5p');
    }

    wp_send_json_success(array('messages' => $messages));
  }

  /**
   * Install one content type from the H5P Hub.
   *
   * Repeats the order of H5PEditorAjax::libraryInstall() with public calls only, so it can also be
   * called from a scheduling endpoint later. Installs one content type per request, because the
   * temporary upload path is cached statically per request. Never throws: the outcome comes back as
   * a 'status' and 'messages' pair, so a bulk queue can continue past a failed item.
   */
  public function install_hub_library($machine_name) {
    if (get_option('h5p_hub_is_enabled', TRUE) != TRUE) {
      return array(
        'status' => 'error',
        'messages' => array(
          'info' => array(),
          // Same msgid as handle_update_content_type_cache(), so the translations stay in sync.
          'error' => array(__('The H5P Hub is disabled. Enable it in the H5P settings to install content types.', 'h5p')),
        ),
      );
    }

    $cached = null;
    // getContentTypeCache($name) returns no version columns, so reduce the whole cache to the newest
    // version of this name, like get_library_overview() does.
    foreach ((array) $this->get_hub_cache() as $row) {
      if ($row->machine_name !== $machine_name) {
        continue;
      }
      if ($cached === null || $this->compare_library_versions($row, $cached) > 0) {
        $cached = $row;
      }
    }
    if ($cached === null) {
      // Same msgid as core's H5PEditorAjax::libraryInstall(), so the translations stay in sync.
      return array(
        'status' => 'error',
        'messages' => array(
          'info' => array(),
          'error' => array(__('The chosen content type is invalid.', 'h5p')),
        ),
      );
    }

    if (!$this->is_hub_library_compatible($cached) || !$this->can_install_hub_library($cached)) {
      // Same msgid as core's H5PEditorAjax::libraryInstall(), so the translations stay in sync.
      return array(
        'status' => 'error',
        'messages' => array(
          'info' => array(),
          'error' => array(__('You do not have permission to install content types. Contact the administrator of your site.', 'h5p')),
        ),
      );
    }

    // An earlier item of the same run can have installed this content type as a dependency.
    $interface = H5P_Plugin::get_instance()->get_h5p_instance('interface');
    foreach ($interface->loadLibraries() as $name => $versions) {
      if ($name !== $machine_name) {
        continue;
      }
      foreach ($versions as $version) {
        if ($this->compare_library_versions($version, $cached) >= 0) {
          return array(
            'status' => 'skipped',
            'messages' => array(
              'info' => array(),
              'error' => array(),
            ),
          );
        }
      }
    }

    $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
    $core->mayUpdateLibraries(TRUE);

    $path = $interface->getUploadedH5pPath();
    $response = $interface->fetchExternalData(
      H5PHubEndpoints::createURL(H5PHubEndpoints::CONTENT_TYPES . $machine_name),
      NULL,
      TRUE,
      empty($path) ? TRUE : $path
    );

    $valid = FALSE;
    if ($response) {
      $valid = (new H5PValidator($interface, $core))->isValidPackage(TRUE, FALSE);
    }

    if ($valid) {
      (new H5PStorage($interface, $core))->savePackage(NULL, NULL, TRUE);
      $status = 'installed';
    }
    else {
      $status = 'error';
    }

    // The temporary paths are static per request, so clean up on every exit path. Core removes the
    // .h5p file in some cases, hence the @.
    H5PCore::deleteFileTree($interface->getUploadedH5pFolderPath());
    @unlink($path);

    $messages = $this->collect_h5p_messages();
    if ($status === 'error' && empty($messages['error'])) {
      // A non-2xx hub answer sets no message of its own.
      $messages['error'][] = __('The content type could not be downloaded. Please try again.', 'h5p');
    }

    return array('status' => $status, 'messages' => $messages);
  }

  /**
   * Handle AJAX request to install one content type from the H5P Hub.
   *
   * Always answers success, with the outcome in the data: a failed item is data for the bulk queue,
   * not a fatal error.
   */
  public function handle_library_install() {
    $this->verify_library_management_request();

    // Hub downloads are slow, like core's.
    @set_time_limit(0);

    $machine_name = filter_input(INPUT_POST, 'machineName', FILTER_SANITIZE_SPECIAL_CHARS);

    wp_send_json_success($this->install_hub_library($machine_name));
  }

  /**
   * Handle AJAX request to update the content type cache from the H5P Hub.
   *
   * Works like the "Update" button of the Libraries page (H5PLibraryAdmin::process_libraries()).
   */
  public function handle_update_content_type_cache() {
    $this->verify_library_management_request();

    // The button is only shown with the hub enabled, the same per-blog option get_library_overview() reads.
    if (get_option('h5p_hub_is_enabled', TRUE) != TRUE) {
      wp_send_json_error(array(
        'messages' => array(
          'info' => array(),
          'error' => array(__('The H5P Hub is disabled. Enable it in the H5P settings to install content types.', 'h5p')),
        ),
      ));
    }

    $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
    try {
      $result = $core->updateContentTypeCache();
    }
    catch (Exception $exception) {
      error_log('H5P network management: ' . $exception->getMessage());
      $result = FALSE;
    }

    // Core sets the messages itself, e.g. "Library cache was successfully updated!" or why it failed.
    $messages = $this->collect_h5p_messages();
    if ($result === FALSE || !empty($messages['error'])) {
      if (empty($messages['error'])) {
        $messages['error'][] = __('The content type cache could not be updated. Please try again.', 'h5p');
      }
      wp_send_json_error(array('messages' => $messages));
    }

    wp_send_json_success(array('messages' => $messages));
  }

  /**
   * Handle AJAX request to rebuild the content caches (filtered parameters) of all blogs, one batch at a time.
   *
   * Works like the "Rebuild cache" button of the Libraries page. Its endpoint, wp_ajax_h5p_rebuild_cache
   * (H5PNetworkLibraryAdmin::ajax_rebuild_cache()), is not reused: it checks no nonce, and it fetches H5PCore
   * once before looping over the blogs, so filterParameters() deletes and creates the export files in the
   * folders of the main blog instead of the content's blog. get_h5p_instance() keys its instances by the
   * current blog, so H5PCore is fetched per blog here.
   */
  public function handle_rebuild_cache() {
    $this->verify_library_management_request();

    $start = microtime(TRUE);

    $left = 0;
    H5PCommons::for_each_blog(function () use (&$left) {
      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $left += (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table_contents} WHERE filtered = ''");
    });

    // Rebuild as many caches as fit in the time budget, so a big network takes several requests.
    H5PCommons::for_each_blog(function () use (&$left, $start) {
      global $wpdb;

      if ($left <= 0) {
        return;
      }

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
      if (!$this->table_exists($table_contents)) {
        return; // Blog has no H5P content tables, so nothing to rebuild.
      }

      $contents = $wpdb->get_results("SELECT id FROM {$table_contents} WHERE filtered = ''");
      if (empty($contents)) {
        return;
      }

      $core = H5P_Plugin::get_instance()->get_h5p_instance('core');
      foreach ($contents as $content) {
        if ((microtime(TRUE) - $start) > H5PLibraryAdmin::UPGRADE_BATCH_TIMEOUT || $left <= 0) {
          break;
        }

        $content = $core->loadContent($content->id);
        $core->filterParameters($content);
        $left--;
      }
    });

    wp_send_json_success(array('left' => $left));
  }

  /**
   * Verify that an AJAX request may manage the network libraries, or end it with an error.
   *
   * Same checks as handle_delete_library(): the nonce, and the capabilities for the network libraries.
   */
  private function verify_library_management_request() {
    $this->verifyNetworkNonce();

    if (!current_user_can('manage_network') || !H5PCommons::current_user_can_manage_libraries()) {
      wp_send_json_error(
        array('message' => __('Permission denied.', 'h5p')),
        H5PCommons::HTTP_FORBIDDEN
      );
    }
  }

  /**
   * Take the messages that H5P core has set during this request.
   *
   * @return array Lists of 'info' and 'error' messages as plain text, as the page shows them as text.
   */
  private function collect_h5p_messages() {
    $interface = H5P_Plugin::get_instance()->get_h5p_instance('interface');

    $messages = array('info' => array(), 'error' => array());
    foreach (array_keys($messages) as $type) {
      foreach ((array) $interface->getMessages($type) as $message) {
        // Error messages are objects with a code, info messages are strings.
        $messages[$type][] = wp_strip_all_tags(is_object($message) ? $message->message : $message);
      }
    }

    return $messages;
  }

  /**
   * Switch network mode on or off.
   *
   * @param bool $enabled Whether network mode should be enabled.
   */
  private function set_network_mode($enabled) {
    H5PCommons::set_network_enabled($enabled);
    H5P_Plugin::assign_capabilities_all_blogs();
  }

  /**
   * Verify the AJAX request nonce.
   *
   * @throws Exception If the nonce is invalid.
   */
  protected function verifyNetworkNonce() {
    check_ajax_referer('h5p_network_ajax', 'nonce', true);
  }
}
