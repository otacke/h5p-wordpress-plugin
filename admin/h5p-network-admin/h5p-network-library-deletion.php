<?php

/**
 * H5P_Network_Library_Deletion
 *
 * Decides which network library versions are deletable and deletes them, incl. circular editor dependencies.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Deletion {

  /**
   * Sub content reference results by name and major.minor; null outside with_cached_references(), so queries are fresh.
   *
   * @var array|null
   */
  private $subcontent_references = null;

  /**
   * Run a callback with sub content reference results cached.
   *
   * Checks scan all blogs' parameters repeatedly, which don't change meanwhile. Nesting ok: outermost call owns cache.
   *
   * @param callable $callback
   *
   * @return mixed Result of the callback.
   */
  public function with_cached_references(callable $callback) {
    $outermost = $this->subcontent_references === null;
    if ($outermost) {
      $this->subcontent_references = array();
    }

    try {
      return $callback();
    }
    finally {
      if ($outermost) {
        $this->subcontent_references = null;
      }
    }
  }

  /**
   * Index the network library dependency table by both ends of each edge.
   *
   * Self-referential rows (library listing itself) are ignored: no dependency, no deletion block.
   *
   * @return array 'dependents': required id => requiring ids; 'types': "library_id:required_library_id" => type.
   */
  public function get_dependency_indexes() {
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
   * Collect data for delete decisions and overview icons: all versions, content counts of all blogs, dependencies.
   *
   * @param array|null $content_counts From H5P_Network_Library_Helpers::get_content_counts(); reloaded when null.
   *
   * @return array
   */
  public function get_deletion_model($content_counts = null) {
    global $wpdb;

    $table_libraries = H5PCommons::build_full_db_table_name('h5p_libraries');

    $libraries = array();
    foreach ((array) $wpdb->get_results(
      "SELECT id, name, major_version, minor_version, patch_version, add_to, has_icon AS hasIcon FROM {$table_libraries}"
    ) as $row) {
      $libraries[(int) $row->id] = array(
        'id' => (int) $row->id,
        'name' => $row->name,
        'majorVersion' => (int) $row->major_version,
        'minorVersion' => (int) $row->minor_version,
        'patchVersion' => (int) $row->patch_version,
        'hasIcon' => (bool) $row->hasIcon,
        'addTo' => (string) $row->add_to,
      );
    }

    return array(
      'libraries' => $libraries,
      'contentCounts' => $content_counts === null ? H5P_Network_Library_Helpers::get_content_counts() : $content_counts,
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
   * Count versions requiring $id as preloaded/editor dependency (each once, self-references excluded), for Info.
   *
   * @param int $id
   * @param array $indexes From get_dependency_indexes().
   *
   * @return int
   */
  public function count_preloaded_or_editor_dependents($id, $indexes) {
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
   * Decide whether library version is deletable, and whether its circular editor dependency must go with it.
   *
   * Deletable if no content uses it (as main or sub content) and no other library depends on it. Addons always are.
   * Circular pair (editor dependency one way, preloaded back) goes together if nothing else needs either.
   *
   * @param array $library Version to check (id, name, majorVersion, minorVersion, addTo).
   * @param array $model From get_deletion_model().
   *
   * @return array With 'deletable' and 'alsoDelete' (id of the circular partner, or null).
   */
  public function is_library_deletable($library, $model) {
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

      // Nothing else may need either, no content may use partner, and no content may embed either as sub content.
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
   * Build REGEXP matching exact version as sub content reference {"library": "Name-1.1"}, also "Name 1.1" (uploads).
   *
   * Uses [ \t] instead of [[:space:]]: older MariaDB REGEXP lacks POSIX classes and would silently match nothing.
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
    H5PCommons::for_each_blog_with_tables(array('h5p_contents'), function () use ($pattern, &$found) {
      if ($found) {
        return H5PCommons::STOP_ITERATION; // One hit on any blog is enough.
      }

      global $wpdb;

      $table_contents = H5PCommons::build_full_db_table_name('h5p_contents');
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
   * Delete a library version, and the circular partner that must go with it.
   *
   * @param int $id
   * @param int|null $also_delete Id of the circular partner, from is_library_deletable().
   * @throws Exception If a version is still there afterwards.
   */
  public function delete_library_version($id, $also_delete) {
    global $wpdb;

    $interface = H5P_Plugin::get_instance()->get_h5p_instance('interface');

    $to_delete = array($id);
    if ($also_delete !== null) {
      $to_delete[] = $also_delete;
    }

    $table_libraries = H5PCommons::build_full_db_table_name('h5p_libraries');
    foreach ($to_delete as $library_id) {
      H5P_Core_Adapter::delete_library_cached_assets($library_id);

      $to_be_deleted = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table_libraries} WHERE id = %d", $library_id)
      );
      if ($to_be_deleted === null) {
        continue;
      }

      $interface->deleteLibrary($to_be_deleted);

      // deleteLibrary() reports nothing, so check that the row is gone.
      $still_there = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_libraries} WHERE id = %d", $library_id));
      if ((int) $still_there !== 0) {
        throw new Exception(
          sprintf(
            /* translators: %s: library name and version, e.g. H5P.Accordion 1.0 */
            __('Failed to delete library %s.', 'h5p'),
            "{$to_be_deleted->name} {$to_be_deleted->major_version}.{$to_be_deleted->minor_version}"
          )
        );
      }
    }
  }

  /**
   * Delete deletable library versions, and those freed by that, until time is up; also usable outside AJAX.
   *
   * Works in passes: within a pass, deleted versions still count as dependents; versions they free go next pass.
   *
   * @param float $deadline microtime(TRUE) after which no new pass is started.
   * @param bool $dry_run Only record what would be deleted, for testing the passes on real data.
   *
   * @return array 'deleted': deleted names and versions; 'more': whether time ran out while last pass still deleted.
   */
  public function delete_deletable_libraries($deadline, $dry_run = false) {
    // Deleting libraries does not change which contents use them.
    $content_counts = H5P_Network_Library_Helpers::get_content_counts();

    $deleted = array();
    $deleted_ids = array();
    $more = false;

    $this->with_cached_references(function () use ($deadline, $dry_run, $content_counts, &$deleted, &$deleted_ids, &$more) {
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
    });

    return array(
      'deleted' => $deleted,
      'more' => $more,
    );
  }
}
