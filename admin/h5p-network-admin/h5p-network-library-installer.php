<?php

/**
 * H5P_Network_Library_Installer
 *
 * Installs and updates network level libraries: from the H5P Hub, from uploaded .h5p packages, and keeps the
 * content type cache of the hub up to date.
 * @package H5P
 * @since 1.19.0
 */
class H5P_Network_Library_Installer {

  /**
   * Check that a hub library is compatible with the installed H5P core API version.
   *
   * @param object $cached
   *
   * @return bool
   */
  public function is_hub_library_compatible($cached) {
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
  public function can_install_hub_library($cached) {
    return H5PCommons::current_user_can_manage_libraries()
      || ((bool) $cached->is_recommended && H5PCommons::current_user_can_install_recommended_libraries());
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
          // Same msgid as update_content_type_cache(), so the translations stay in sync.
          'error' => array(__('The H5P Hub is disabled. Enable it in the H5P settings to install content types.', 'h5p')),
        ),
      );
    }

    $cached = null;
    // getContentTypeCache($name) returns no version columns, so reduce the whole cache to the newest
    // version of this name, like H5P_Network_Library_Overview::get_library_overview() does.
    foreach ((array) H5P_Network_Library_Helpers::get_hub_cache() as $row) {
      if ($row->machine_name !== $machine_name) {
        continue;
      }
      if ($cached === null || H5P_Network_Library_Helpers::compare_library_versions($row, $cached) > 0) {
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
        if (H5P_Network_Library_Helpers::compare_library_versions($version, $cached) >= 0) {
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

    $messages = H5P_Network_Library_Helpers::collect_h5p_messages();
    if ($status === 'error' && empty($messages['error'])) {
      // A non-2xx hub answer sets no message of its own.
      $messages['error'][] = __('The content type could not be downloaded. Please try again.', 'h5p');
    }

    return array('status' => $status, 'messages' => $messages);
  }

  /**
   * Install or update libraries from the uploaded .h5p package.
   *
   * Works like the upload on the Libraries page (H5PLibraryAdmin::process_libraries()) and shares its
   * H5P_Plugin_Admin::handle_upload(), which also honours the "Disable file extension check" option.
   *
   * @param int $upload_error UPLOAD_ERR_* code of the uploaded file.
   * @param bool $upgrade_only Whether only libraries that are already installed may be updated.
   *
   * @return array With 'success' and 'messages', lists of 'info' and 'error' messages.
   */
  public function upload_library_package($upload_error, $upgrade_only) {
    if ($upload_error !== UPLOAD_ERR_OK) {
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

      return array(
        'success' => FALSE,
        'messages' => array(
          'info' => array(),
          'error' => array(
            isset($upload_errors[$upload_error])
              ? $upload_errors[$upload_error]
              : __('The library package could not be uploaded. Please try again.', 'h5p')
          ),
        ),
      );
    }

    $result = H5P_Plugin_Admin::get_instance()->handle_upload(NULL, $upgrade_only);

    $messages = H5P_Network_Library_Helpers::collect_h5p_messages();
    if ($result === FALSE || !empty($messages['error'])) {
      return array('success' => FALSE, 'messages' => $messages);
    }

    // Core reports only added or updated libraries, so without this the notice after the reload would be empty.
    if (empty($messages['info'])) {
      $messages['info'][] = __('The package was valid, but no libraries were added or updated.', 'h5p');
    }

    return array('success' => TRUE, 'messages' => $messages);
  }

  /**
   * Update the content type cache from the H5P Hub.
   *
   * Works like the "Update" button of the Libraries page (H5PLibraryAdmin::process_libraries()).
   *
   * @return array With 'success' and 'messages', lists of 'info' and 'error' messages.
   */
  public function update_content_type_cache() {
    // The button is only shown with the hub enabled, the same per-blog option
    // H5P_Network_Library_Overview::get_library_overview() reads.
    if (get_option('h5p_hub_is_enabled', TRUE) != TRUE) {
      return array(
        'success' => FALSE,
        'messages' => array(
          'info' => array(),
          'error' => array(__('The H5P Hub is disabled. Enable it in the H5P settings to install content types.', 'h5p')),
        ),
      );
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
    $messages = H5P_Network_Library_Helpers::collect_h5p_messages();
    if ($result === FALSE || !empty($messages['error'])) {
      if (empty($messages['error'])) {
        $messages['error'][] = __('The content type cache could not be updated. Please try again.', 'h5p');
      }
      return array('success' => FALSE, 'messages' => $messages);
    }

    return array('success' => TRUE, 'messages' => $messages);
  }
}
