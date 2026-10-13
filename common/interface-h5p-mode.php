<?php
/**
 * H5P_Mode
 *
 * What differs between local mode (every blog manages its own libraries) and network mode (the network shares
 * one set of libraries, content stays on each blog). Plugin code asks the current mode, H5PCommons::mode(),
 * instead of branching on the network flag.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */
interface H5P_Mode {

  /**
   * Whether every blog manages its own libraries: has library tables, a Libraries page and blog cached assets.
   *
   * @return bool
   */
  public function manages_libraries_per_blog();

  /**
   * Get path of the folder that holds the libraries folder.
   *
   * @return string
   */
  public function get_libraries_base_path();

  /**
   * Get URL of the folder that holds the libraries folder.
   *
   * @return string
   */
  public function get_libraries_base_url();

  /**
   * Get URL of a library file.
   *
   * @param string $library_folder_name e.g. H5P.Accordion-1.0
   * @param string $file_name Path inside the library folder.
   * @return string
   */
  public function get_library_file_url($library_folder_name, $file_name);

  /**
   * Get full name of a library table (SCOPE_LIBRARY).
   *
   * @param string $table_name Table name without prefix.
   * @return string
   */
  public function get_library_table_name($table_name);

  /**
   * Get file storage for H5PCore: a path for core's default storage, or a storage object.
   *
   * @param string $h5p_path Path of the current blog's h5p folder.
   * @return string|H5PFileStorage
   */
  public function get_file_storage($h5p_path);

  /**
   * Make the paths of library and cached asset files point where they live, for core and the editor.
   *
   * @param array $files 'scripts' and 'styles' lists of objects with a 'path', changed in place.
   */
  public function rewrite_asset_paths(&$files);

  /**
   * Get the URL core JS builds library URLs from, if it differs from core's default (the blog's h5p folder).
   *
   * @return string|null
   */
  public function get_libraries_url_override();

  /**
   * Get the capability that grants a library capability to roles.
   *
   * @param string $local_cap Capability it is mapped from in local mode.
   * @return string|array
   */
  public function get_library_capability_source($local_cap);

  /**
   * Whether the current user may use the library capabilities at all, besides holding them.
   *
   * @return bool
   */
  public function current_user_may_write_libraries();

  /**
   * Build SQL expression telling whether a library is restricted on the current blog, as '1' or '0'.
   *
   * @param string $alias Alias or full name of the libraries table in the query.
   * @return string
   */
  public function get_restricted_column_sql($alias);

  /**
   * Run callback on every blog whose content uses the libraries: the current blog, or all blogs.
   *
   * @param string[] $table_names Tables a blog must have to be visited (network mode only; locally the blog's own
   *   tables are the caller's business).
   * @param callable $callback Receives the blog id, in the blog's context.
   */
  public function for_each_content_blog(array $table_names, callable $callback);

  /**
   * Forget what the mode keeps about a deleted library version beyond its own tables.
   *
   * @param int $library_id
   */
  public function forget_library($library_id);

  /**
   * Forget what the mode keeps about a deleted blog beyond the blog's own tables.
   *
   * @param int $blog_id
   */
  public function forget_blog($blog_id);

  /**
   * Create the library admin for this mode.
   *
   * @param string $plugin_slug
   * @return H5PLibraryAdmin
   */
  public function create_library_admin($plugin_slug);
}
