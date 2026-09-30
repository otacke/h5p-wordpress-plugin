<?php

/**
 * H5P_Network_Library_Overview
 *
 * Collects what the network level management page shows: the installed and available libraries with their
 * icons, updates, upgrade targets, deletability and info messages, and the settings of the page script.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Library_Overview {

  /**
   * Decides the deletability of the installed libraries.
   *
   * @var H5P_Network_Library_Deletion
   */
  private $deletion;

  /**
   * Decides whether hub libraries can be installed.
   *
   * @var H5P_Network_Library_Installer
   */
  private $installer;

  /**
   * Set up the overview with the classes it asks about deletability and installability.
   *
   * @param H5P_Network_Library_Deletion $deletion Decides the deletability of the installed libraries.
   * @param H5P_Network_Library_Installer $installer Decides whether hub libraries can be installed.
   */
  public function __construct($deletion, $installer) {
    $this->deletion = $deletion;
    $this->installer = $installer;
  }

  /**
   * Collect the installed and available libraries for the network management page.
   *
   * @return array
   */
  public function get_library_overview() {
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
    foreach ((array) H5P_Network_Library_Helpers::get_hub_cache() as $cached) {
      if (!isset($hub[$cached->machine_name])
          || H5P_Network_Library_Helpers::compare_library_versions($cached, $hub[$cached->machine_name]) > 0) {
        $hub[$cached->machine_name] = $cached;
      }
    }

    // Content counts per library version across all blogs, for the upgrade and delete actions.
    $content_counts = H5P_Network_Library_Helpers::get_content_counts();

    $metadata = $this->load_library_metadata();
    $installed = array();
    $installed_names = array();
    foreach ($interface->loadLibraries() as $name => $versions) {
      $installed_names[$name] = TRUE;

      // loadLibraries() does not sort, so find the newest installed version explicitly.
      $newest = $versions[0];
      foreach ($versions as $version) {
        if (H5P_Network_Library_Helpers::compare_library_versions($version, $newest) > 0) {
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
      'dependencies' => $this->deletion->get_dependency_indexes(),
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
      $deletion = $this->deletion->is_library_deletable(
        $deletion_model['libraries'][$row['id']],
        $deletion_model
      );
      $installed[$index]['deletable'] = $deletion['deletable'];
      $installed[$index]['alsoDelete'] = $deletion['alsoDelete'];
      $installed[$index]['infoMessageHtml'] = $this->build_library_info_html(
        $row,
        isset($hub[$row['machineName']]) ? $hub[$row['machineName']] : null,
        $row['contentCount'],
        $this->deletion->count_preloaded_or_editor_dependents($row['id'], $deletion_model['dependencies']),
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
        'canInstall' => $this->installer->is_hub_library_compatible($cached)
          && $this->installer->can_install_hub_library($cached),
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
  public function get_upgrade_settings() {
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
    if (H5P_Network_Library_Helpers::compare_library_versions($cached, $library) <= 0
        || !$this->installer->is_hub_library_compatible($cached)
        || !$this->installer->can_install_hub_library($cached)) {
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
      array(__('Version', 'h5p'), H5P_Network_Library_Helpers::format_library_version($row)),
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
  public function get_library_l10n() {
    return array(
      'cancel' => __('Cancel', 'h5p'),
      'close' => __('Close', 'h5p'),
      'confirm' => __('Update', 'h5p'),
      'requestFailed' => __('The library could not be installed or updated. Please try again.', 'h5p'),
      'deleteFailed' => __('The library could not be deleted. Please try again.', 'h5p'),
      'working' => __('Working...', 'h5p'),
      'dismiss' => __('Dismiss this notice.', 'h5p'),
      'uploadFailed' => __('The library package could not be uploaded. Please try again.', 'h5p'),
      // Same msgid as the upload error in H5P_Network_Library_Installer::upload_library_package(), so the two
      // stay in sync.
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
}
