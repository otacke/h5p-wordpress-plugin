<?php
/**
 * H5P_Mode_Local
 *
 * Local mode: every blog manages its own libraries in its own h5p folder and tables.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
class H5P_Mode_Local implements H5P_Mode {

  public function manages_libraries_per_blog() {
    return true;
  }

  public function get_libraries_base_path() {
    return H5P_Plugin::get_instance()->get_h5p_path();
  }

  public function get_libraries_base_url() {
    return H5P_Plugin::get_instance()->get_h5p_url();
  }

  public function get_library_file_url($library_folder_name, $file_name) {
    // wp_upload_dir() as base, as H5P core and editor expect library files in blog's own h5p folder there.
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'] . '/h5p/libraries/' . $library_folder_name . '/' . $file_name;
  }

  public function get_library_table_name($table_name) {
    return H5P_Tables::build_full_db_table_name_singlesite($table_name);
  }

  public function get_file_storage($h5p_path) {
    // H5PCore builds the default storage from the plain h5p folder itself.
    return $h5p_path;
  }

  public function rewrite_asset_paths(&$files) {
    // The blog's own h5p folder is where core and the editor look already.
  }

  public function get_libraries_url_override() {
    return null;
  }

  public function get_library_capability_source($local_cap) {
    return $local_cap;
  }

  public function current_user_may_write_libraries() {
    return true;
  }

  public function get_restricted_column_sql($alias) {
    return "{$alias}.restricted";
  }

  public function for_each_content_blog(array $table_names, callable $callback) {
    $callback(get_current_blog_id());
  }

  public function forget_library($library_id) {
    // The restricted flag lives in the library row, which is gone with it.
  }

  public function forget_blog($blog_id) {
    // Everything lives in the blog's own tables.
  }

  public function create_library_admin($plugin_slug) {
    return new H5PLibraryAdmin($plugin_slug);
  }
}
