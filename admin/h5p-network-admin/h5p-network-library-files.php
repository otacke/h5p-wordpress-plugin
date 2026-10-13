<?php

/**
 * H5P_Network_Library_Files
 *
 * Library directories during the migration to network level: copying each blog's library folders to the network
 * folder, in the order of the blog's library table, and clearing the blog folders afterwards.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Files extends H5P_Network_Admin_Base {

  /**
   * Copy library directories of one blog to network level.
   *
   * Records copies in $network_libraries_installed, keeping only highest patch of each major.minor
   * across all blogs. Passed in and out so batches can carry it over several requests.
   *
   * @param int    $blog_id                     Blog to copy from.
   * @param string $network_libraries_path      Network-level libraries path.
   * @param array  $network_libraries_installed Record of copies so far, updated in place.
   *
   * @throws Exception If file system operation fails.
   */
  public function copyBlogLibrariesToNetwork($blog_id, $network_libraries_path, &$network_libraries_installed) {
    $upload_directory = wp_upload_dir();
    $libraries_directory = "{$upload_directory['basedir']}/h5p/libraries";

    if (!is_dir($libraries_directory)) {
      return; // Blog has no H5P libraries directory yet, so nothing to migrate.
    }

    $library_directories = scandir($libraries_directory);
    if ($library_directories === false) {
      throw new Exception(
        sprintf(
          /* translators: %s: directory path */
          __('Failed to scan libraries directory: %s', 'h5p'),
          $libraries_directory
        )
      );
    }

    // Network table auto-increment ids follow copy order, so process directories in the blog's
    // own table id order to keep the network ids aligned with the blog's.
    $library_directories = $this->orderLibraryDirectories($library_directories);

    foreach ($library_directories as $library_directory_name) {
      $result = $this->processLibraryDirectory(
        $libraries_directory,
        $library_directory_name,
        $network_libraries_path,
        $blog_id
      );

      if ($result === null) {
        continue;
      }

      $existing_version = $network_libraries_installed[$result['versioned_machine_name']]['version'] ?? '';
      if ($existing_version !== '' && !$this->isLatestPatchVersion($existing_version, $result['version'])) {
        continue;
      }

      $network_libraries_installed[$result['versioned_machine_name']] = $result;
    }
  }

  /**
   * Order library directory names so directories with a row in the blog's h5p_libraries
   * table come first, in that table's id order; the remaining directories follow.
   *
   * Must run in the blog's context, since the table name resolves from the current blog.
   *
   * @param array $directory_names Directory names as returned by scandir().
   * @return array Ordered directory names.
   */
  public function orderLibraryDirectories($directory_names) {
    $table = H5PCommons::build_full_db_table_name_singlesite('h5p_libraries');

    if (!H5PCommons::table_exists($table)) {
      return $directory_names;
    }

    global $wpdb;

    $rows = $wpdb->get_results("SELECT name, major_version, minor_version FROM {$table} ORDER BY id");
    if ($rows === null) {
      return $directory_names;
    }

    $present = array_flip($directory_names);
    $ordered = array();

    foreach ($rows as $row) {
      $name = $row->name . '-' . (int) $row->major_version . '.' . (int) $row->minor_version;

      if (isset($present[$name])) {
        $ordered[] = $name;
        unset($present[$name]);
      }
    }

    return array_merge($ordered, array_keys($present));
  }

  /**
   * Process single library directory: read library.json, copy if needed.
   *
   * @param string $libraries_directory    Parent libraries directory path.
   * @param string $library_directory_name Library directory name.
   * @param string $network_libraries_path Network-level libraries path.
   * @param int    $blog_id                Blog ID.
   * @return array|null Associative array with relevant params.
   */
  public function processLibraryDirectory(
    $libraries_directory,
    $library_directory_name,
    $network_libraries_path,
    $blog_id
  ) {
    if ($library_directory_name[0] === '.') {
      return null;
    }

    $library_path = "{$libraries_directory}/{$library_directory_name}";
    if (!is_dir($library_path)) {
      return null;
    }

    $library_json_path = "{$library_path}/library.json";
    if (!file_exists($library_json_path)) {
      return null;
    }

    $library_data = $this->readVersionedInfoFromLibraryJson($library_json_path);
    if ($library_data === null) {
      throw new Exception(
        sprintf(
          /* translators: %s: library directory path */
          __('Failed to parse library.json from %s', 'h5p'),
          $library_path
        )
      );
    }

    $machine_name = $library_data['machineName'];
    $version = "{$library_data['majorVersion']}.{$library_data['minorVersion']}.{$library_data['patchVersion']}";
    $versioned_machine_name = "{$machine_name}-{$library_data['majorVersion']}.{$library_data['minorVersion']}";

    if (!$this->copyDirectory($library_path, $network_libraries_path)) {
      throw new Exception(
        sprintf(
          /* translators: 1: library name and version, 2: library directory path */
          __('Failed to copy library %1$s from %2$s', 'h5p'),
          $versioned_machine_name,
          $library_path
        )
      );
    }

    return array(
      'versioned_machine_name' => $versioned_machine_name,
      'machine_name'           => $machine_name,
      'version'                => $version,
      'blog_id'                => $blog_id,
    );
  }

  /**
   * Clear migrated H5P files of one blog, keeping directories themselves.
   *
   * Skips absent directories, so it can run again on blogs already cleared.
   *
   * @param WP_Filesystem_Base $wp_filesystem Filesystem to delete through.
   *
   * @throws Exception If existing file or directory cannot be deleted.
   */
  public function clearBlogLibrariesAndCachedassets($wp_filesystem) {
    $upload_directory = wp_upload_dir();
    $directories = array(
      "{$upload_directory['basedir']}/h5p/libraries",
      "{$upload_directory['basedir']}/h5p/cachedassets",
    );

    foreach ($directories as $directory) {
      if (!$wp_filesystem->is_dir($directory)) {
        continue;
      }

      foreach (scandir($directory) as $entry) {
        if ($entry[0] === '.') {
          continue;
        }

        $entry_path = "{$directory}/{$entry}";

        if (is_dir($entry_path)) {
          $deleted = $wp_filesystem->rmdir($entry_path, true);
        } else {
          $deleted = $wp_filesystem->delete($entry_path);
        }

        if (!$deleted) {
          throw new Exception(
            sprintf(
              /* translators: %s: file or directory path */
              __('Failed to delete: %s', 'h5p'),
              $entry_path
            )
          );
        }
      }
    }
  }
}
