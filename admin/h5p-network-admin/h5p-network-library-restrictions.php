<?php

/**
 * H5P_Network_Library_Restrictions
 *
 * Collects what the network restrictions page shows, and saves what it changes: which runnable library
 * versions are restricted on which blogs. The page has two views, one library version with a paged list of
 * blogs, and one blog with all runnable library versions, since the blogs may be too many for one matrix.
 * @package H5P
 * @since 1.18.0
 */
class H5P_Network_Library_Restrictions {

  /** View listing the blogs of one library version. */
  const TAB_LIBRARIES = 'libraries';

  /** View listing the library versions of one blog. */
  const TAB_SITES = 'sites';

  /** Number of blogs per page of a blog list. */
  const SITES_PER_PAGE = 20;

  /**
   * Get runnable network library versions, ordered for display.
   *
   * @return array Library rows with id, name, title, major_version, minor_version and patch_version.
   */
  public function get_runnable_libraries() {
    global $wpdb;

    $table_libraries = H5PCommons::build_full_db_table_name_multisite('h5p_libraries');

    return $wpdb->get_results(
      "SELECT id, name, title, major_version, minor_version, patch_version
         FROM {$table_libraries}
        WHERE runnable = 1
        ORDER BY title, name, major_version, minor_version"
    );
  }

  /**
   * Build what the page shows for the request.
   *
   * @param array $request Query arguments: tab, library, blog, s and paged.
   * @return array Page model.
   */
  public function get_page_model($request) {
    $tab = (isset($request['tab']) && $request['tab'] === self::TAB_SITES) ? self::TAB_SITES : self::TAB_LIBRARIES;
    $search = isset($request['s']) ? sanitize_text_field(wp_unslash($request['s'])) : '';
    $paged = isset($request['paged']) ? max(1, (int) $request['paged']) : 1;

    $libraries = $this->get_runnable_libraries();
    $counts = H5P_Library_Restrictions::count_blogs_per_library();
    foreach ($libraries as $library) {
      $library->restricted_blogs = isset($counts[(int) $library->id]) ? $counts[(int) $library->id] : 0;
    }

    $model = array(
      'tab'          => $tab,
      'search'       => $search,
      'paged'        => $paged,
      'libraries'    => $libraries,
      'totalSites'   => H5PCommons::count_blogs(),
      'library'      => null,
      'site'         => null,
      'sites'        => array(),
      'matchedSites' => 0,
    );

    if ($tab === self::TAB_LIBRARIES) {
      // Without a choice, the first version is shown, so the page never opens empty.
      $library_id = isset($request['library']) ? (int) $request['library'] : 0;
      if ($library_id === 0 && !empty($libraries)) {
        $library_id = (int) $libraries[0]->id;
      }
      foreach ($libraries as $library) {
        if ((int) $library->id === $library_id) {
          $model['library'] = $library;
        }
      }

      if ($model['library'] !== null) {
        $restricted = array_flip(H5P_Library_Restrictions::get_blog_ids($library_id));
        list($model['sites'], $model['matchedSites']) = $this->find_sites($search, $paged);
        foreach ($model['sites'] as &$site) {
          $site['restricted'] = isset($restricted[$site['id']]);
        }
        unset($site);
      }
    }
    else {
      list($model['sites'], $model['matchedSites']) = $this->find_sites($search, $paged);

      $blog_id = isset($request['blog']) ? (int) $request['blog'] : 0;
      $site = $blog_id > 0 ? get_site($blog_id) : null;
      if ($site !== null) {
        $model['site'] = $this->describe_site($site);
        $model['site']['restrictedLibraries'] = array_flip(H5P_Library_Restrictions::get_library_ids($blog_id));
      }
    }

    return $model;
  }

  /**
   * Find one page of blogs whose address matches the search.
   *
   * @param string $search Search term, empty for all blogs.
   * @param int    $paged  Page number, from 1.
   * @return array Blogs of the page as described by describe_site(), and number of matching blogs.
   */
  protected function find_sites($search, $paged) {
    $query = $this->build_site_query($search);

    $total = get_sites(array_merge($query, array('count' => true)));
    $sites = get_sites(array_merge($query, array(
      'number' => self::SITES_PER_PAGE,
      'offset' => ($paged - 1) * self::SITES_PER_PAGE,
    )));

    return array(array_map(array($this, 'describe_site'), $sites), (int) $total);
  }

  /**
   * Build get_sites() arguments matching blogs by address.
   *
   * @param string $search Search term, empty for all blogs.
   * @return array
   */
  protected function build_site_query($search) {
    $query = array(
      'number'  => 0,
      'orderby' => 'id',
      'order'   => 'ASC',
    );

    if ($search !== '') {
      $query['search'] = $search;
      $query['search_columns'] = array('domain', 'path');
    }

    return $query;
  }

  /**
   * Describe a blog for display.
   *
   * @param WP_Site $site
   * @return array With id, name and address.
   */
  protected function describe_site($site) {
    return array(
      'id'      => (int) $site->blog_id,
      'name'    => $site->blogname,
      'address' => untrailingslashit($site->domain . $site->path),
    );
  }

  /**
   * Restrict and allow pairs of library version and blog, as changed on one page of the restrictions page.
   *
   * Pairs naming a library version that is not runnable, or a blog that does not exist, are ignored.
   *
   * @param string[] $restrict Pairs "libraryId:blogId" to restrict.
   * @param string[] $allow    Pairs "libraryId:blogId" to allow.
   * @return int Number of pairs saved.
   * @throws Exception If database update fails.
   */
  public function save_pairs($restrict, $allow) {
    $groups = array(
      'restrict' => $this->group_pairs_by_library($restrict),
      'allow'    => $this->group_pairs_by_library($allow),
    );

    $saved = 0;
    foreach ($groups as $action => $blog_ids_by_library) {
      foreach ($blog_ids_by_library as $library_id => $blog_ids) {
        H5P_Library_Restrictions::set_restricted(array($library_id), $blog_ids, $action === 'restrict');
        $saved += count($blog_ids);
      }
    }

    return $saved;
  }

  /**
   * Restrict or allow a library version on every blog whose address matches the search.
   *
   * @param int    $library_id Network library id.
   * @param string $search     Search term, empty for all blogs.
   * @param bool   $restricted True to restrict, false to allow.
   * @return int Number of blogs affected, 0 if the library version is not runnable.
   * @throws Exception If database update fails.
   */
  public function apply_to_matching_sites($library_id, $search, $restricted) {
    if (!in_array((int) $library_id, $this->get_runnable_library_ids(), true)) {
      return 0;
    }

    $blog_ids = array_map('intval', get_sites(array_merge(
      $this->build_site_query($search),
      array('fields' => 'ids')
    )));

    H5P_Library_Restrictions::set_restricted(array($library_id), $blog_ids, $restricted);

    return count($blog_ids);
  }

  /**
   * Parse and validate pairs "libraryId:blogId", grouped by library.
   *
   * @param string[] $pairs
   * @return array Blog ids keyed by library id, only runnable libraries and existing blogs.
   */
  protected function group_pairs_by_library($pairs) {
    $grouped = array();
    foreach ((array) $pairs as $pair) {
      $parts = explode(':', (string) $pair);
      if (count($parts) === 2) {
        $grouped[(int) $parts[0]][] = (int) $parts[1];
      }
    }

    $runnable = array_flip($this->get_runnable_library_ids());
    $grouped = array_intersect_key($grouped, $runnable);
    if (empty($grouped)) {
      return array();
    }

    $existing = array_flip(array_map('intval', get_sites(array(
      'fields'   => 'ids',
      'number'   => 0,
      'site__in' => array_merge(...array_values($grouped)),
    ))));

    foreach ($grouped as $library_id => $blog_ids) {
      $grouped[$library_id] = array_values(array_filter($blog_ids, function ($blog_id) use ($existing) {
        return isset($existing[$blog_id]);
      }));
    }

    return array_filter($grouped);
  }

  /**
   * Get ids of runnable network library versions.
   *
   * @return int[]
   */
  protected function get_runnable_library_ids() {
    return array_map(function ($library) {
      return (int) $library->id;
    }, $this->get_runnable_libraries());
  }
}
