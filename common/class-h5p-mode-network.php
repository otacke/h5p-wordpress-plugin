<?php
/**
 * H5P_Mode_Network
 *
 * Network mode: libraries shared in network folder and tables; content, results and restrictions stay per blog.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Mode_Network implements H5P_Mode {

  public function manages_libraries_per_blog() {
    return false;
  }

  public function get_libraries_base_path() {
    return H5P_Network_Paths::get_h5p_network_path();
  }

  public function get_libraries_base_url() {
    return H5P_Network_Paths::get_h5p_network_url();
  }

  public function get_library_file_url($library_folder_name, $file_name) {
    return H5P_Network_Paths::get_libraries_url() . '/' . $library_folder_name . '/' . $file_name;
  }

  public function get_library_table_name($table_name) {
    return H5P_Tables::build_full_db_table_name_multisite($table_name);
  }

  public function get_file_storage($h5p_path) {
    // Libraries and cached assets are shared by all blogs, content is not: a storage with two roots.
    return new H5P_Network_File_Storage($h5p_path, H5P_Network_Paths::get_h5p_network_path());
  }

  public function rewrite_asset_paths(&$files) {
    // Library and cached asset paths come relative to blog's h5p folder; absolute network URLs pass core unchanged.
    $network_url = H5P_Network_Paths::get_h5p_network_url();

    foreach (array('scripts', 'styles') as $type) {
      if (empty($files[$type])) {
        continue;
      }

      foreach ($files[$type] as $asset) {
        if (preg_match('#^/(libraries|cachedassets)/#', $asset->path) === 1) {
          $asset->path = $network_url . $asset->path;
        }
      }
    }
  }

  public function get_libraries_url_override() {
    // Core JS builds library URLs as url + '/libraries/'; H5P.getLibraryPath() honours this override.
    return H5P_Network_Paths::get_h5p_network_url() . '/libraries';
  }

  public function get_library_capability_source($local_cap) {
    // Libraries folder is shared, so blog roles lose library capabilities; super admins have every capability.
    return array('install_plugins', 'manage_network_plugins');
  }

  public function current_user_may_write_libraries() {
    // Roles are per blog and capabilities can be granted straight to users, so the request is checked, too.
    return current_user_can('manage_network');
  }

  public function get_restricted_column_sql($alias) {
    $table = H5P_Library_Restrictions::get_table();
    $blog_id = (int) get_current_blog_id();

    return "EXISTS(SELECT 1 FROM {$table} hlr WHERE hlr.library_id = {$alias}.id AND hlr.blog_id = {$blog_id})";
  }

  public function for_each_content_blog(array $table_names, callable $callback) {
    if (empty($table_names)) {
      H5P_Blogs::for_each_blog($callback);
      return;
    }

    H5P_Blogs::for_each_blog_with_tables($table_names, $callback);
  }

  public function forget_library($library_id) {
    H5P_Library_Restrictions::delete_for_library($library_id);
  }

  public function forget_blog($blog_id) {
    // Restrictions are kept at network level, so the blog's uninstall leaves them behind.
    H5P_Library_Restrictions::delete_for_blog($blog_id);
  }

  public function create_library_admin($plugin_slug) {
    return new H5PNetworkLibraryAdmin($plugin_slug);
  }
}
