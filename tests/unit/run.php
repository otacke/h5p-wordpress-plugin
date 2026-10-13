<?php
/**
 * Unit tests of the plugin's pure helpers: no WordPress, no database, no dependencies.
 *
 *   php tests/unit/run.php
 *
 * The few WordPress functions the helpers touch are stubbed below. Behaviour that needs WordPress, a database
 * and fixtures (migrations, restrictions, uninstall, the admin pages) is tested by integration tests run against
 * a multisite installation.
 *
 * @package   H5P
 * @license   MIT
 * @link      http://h5p.org
 * @since     1.18.0
 */

// A file in the plugin folder can be requested over the web; the tests are for the command line only.
if (PHP_SAPI !== 'cli') {
  exit;
}

define('ABSPATH', __DIR__ . '/');

// --- WordPress stubs.

function plugin_dir_path($file) {
  return dirname($file) . '/';
}
function __($text, $domain = 'default') {
  return $text;
}
function esc_html($text) {
  return htmlspecialchars($text, ENT_QUOTES);
}
function get_current_blog_id() {
  return 4;
}
function get_site_option($option, $default = false) {
  return $default;
}
$GLOBALS['h5p_unit_doing_it_wrong'] = array();
function _doing_it_wrong($function_name, $message, $version) {
  $GLOBALS['h5p_unit_doing_it_wrong'][] = $message;
}
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_4_', 'base_prefix' => 'wp_');

require __DIR__ . '/../../autoloader.php';

// --- Runner.

$failures = 0;
$passes = 0;

function check($label, $condition) {
  global $failures, $passes;
  if ($condition) {
    $passes++;
    return;
  }
  $failures++;
  echo "FAIL {$label}\n";
}

function set_network_mode($enabled) {
  $flag = new ReflectionProperty('H5P_Network_Mode', 'is_network_enabled');
  $flag->setAccessible(true);
  $flag->setValue(null, $enabled);
}

class H5P_Unit_Utils {
  use H5PUtils;
}

// --- H5P_Core_Adapter::relative_path()

$uploads = '/var/www/wp-content/uploads';
check('relative path from the main blog', H5P_Core_Adapter::relative_path("{$uploads}/h5p", "{$uploads}/h5p_network") === '/../h5p_network');
check('relative path from a subsite', H5P_Core_Adapter::relative_path("{$uploads}/sites/4/h5p", "{$uploads}/h5p_network") === '/../../../h5p_network');
check('relative path with trailing slashes', H5P_Core_Adapter::relative_path("{$uploads}/h5p/", "{$uploads}/h5p_network/") === '/../h5p_network');
check('relative path with backslashes', H5P_Core_Adapter::relative_path('C:\\uploads\\h5p', 'C:\\uploads\\h5p_network') === '/../h5p_network');

// --- H5PUtils::isLatestPatchVersion()

check('newer patch of the same minor', H5P_Unit_Utils::isLatestPatchVersion('1.2.3', '1.2.4') === true);
check('older patch', H5P_Unit_Utils::isLatestPatchVersion('1.2.4', '1.2.3') === false);
check('same version', H5P_Unit_Utils::isLatestPatchVersion('1.2.3', '1.2.3') === false);
check('other minor', H5P_Unit_Utils::isLatestPatchVersion('1.2.3', '1.3.0') === false);

// --- H5P_Network_Library_Helpers

$version = function ($major, $minor, $patch) {
  return (object) array('major_version' => $major, 'minor_version' => $minor, 'patch_version' => $patch);
};
check('compare: equal', H5P_Network_Library_Helpers::compare_library_versions($version(1, 2, 3), $version(1, 2, 3)) === 0);
check('compare: patch', H5P_Network_Library_Helpers::compare_library_versions($version(1, 2, 3), $version(1, 2, 10)) === -1);
check('compare: minor beats patch', H5P_Network_Library_Helpers::compare_library_versions($version(1, 3, 0), $version(1, 2, 99)) === 1);
check('compare: numeric, not textual', H5P_Network_Library_Helpers::compare_library_versions($version('1', '10', '0'), $version('1', '9', '0')) === 1);
check('format version', H5P_Network_Library_Helpers::format_library_version(array('majorVersion' => 1, 'minorVersion' => 2, 'patchVersion' => 3)) === '1.2.3');
check('format name', H5P_Network_Library_Helpers::format_library_name(array('title' => 'Accordion', 'machineName' => 'H5P.Accordion')) === 'Accordion (H5P.Accordion)');

// --- H5P_Library_Restrictions: package main library, collected libraries

$h5p_json = array(
  'mainLibrary' => 'H5P.Column',
  'preloadedDependencies' => array(
    array('machineName' => 'H5P.Text', 'majorVersion' => 1, 'minorVersion' => 1),
    array('machineName' => 'H5P.Column', 'majorVersion' => 1, 'minorVersion' => 18),
  ),
);
check('package main library', H5P_Library_Restrictions::get_package_main_library($h5p_json) === 'H5P.Column 1.18');
check('package main library missing', H5P_Library_Restrictions::get_package_main_library(array('mainLibrary' => 'H5P.Column')) === '');

$collect = new ReflectionMethod('H5P_Library_Restrictions', 'collect_libraries');
$collect->setAccessible(true);
$params = '{"content":[{"content":{"library":"H5P.MultiChoice 1.16","params":{"answers":[{"library":"H5P.Text 1.1"}]}}},{"library":"not a library"}]}';
$collected = $collect->invoke(null, array('machineName' => 'H5P.Column', 'majorVersion' => 1, 'minorVersion' => 18), $params);
check('collects main and nested sub content libraries',
  array_keys($collected) === array('H5P.Column 1.18', 'H5P.MultiChoice 1.16', 'H5P.Text 1.1'));
$collected = $collect->invoke(null, array('name' => 'H5P.Accordion', 'majorVersion' => '1', 'minorVersion' => '0'), json_decode('{}'));
check('main library given with "name", as loaded content has it', array_keys($collected) === array('H5P.Accordion 1.0'));

// --- H5P_Tables: registry and resolution

check('library scope', H5P_Tables::get_table_names(H5P_Tables::SCOPE_LIBRARY)
  === array('h5p_libraries', 'h5p_libraries_libraries', 'h5p_libraries_languages', 'h5p_libraries_cachedassets'));
check('blog scope has nine tables', count(H5P_Tables::get_table_names(H5P_Tables::SCOPE_BLOG)) === 9);
check('every table has one scope', count(H5P_Tables::get_table_names(H5P_Tables::SCOPE_BLOG, H5P_Tables::SCOPE_LIBRARY,
  H5P_Tables::SCOPE_NETWORK_ONLY, H5P_Tables::SCOPE_GLOBAL)) === count(H5P_Tables::TABLES));

set_network_mode(false);
check('local: content table', H5P_Tables::build_full_db_table_name('h5p_contents') === 'wp_4_h5p_contents');
check('local: library table', H5P_Tables::build_full_db_table_name('h5p_libraries') === 'wp_4_h5p_libraries');
check('local: hub cache', H5P_Tables::build_full_db_table_name('h5p_libraries_hub_cache') === 'wp_h5p_libraries_hub_cache');
check('local: restricted column', H5P_Library_Restrictions::restricted_column_sql('hl') === 'hl.restricted');

set_network_mode(true);
check('network: content table', H5P_Tables::build_full_db_table_name('h5p_contents') === 'wp_4_h5p_contents');
check('network: library table', H5P_Tables::build_full_db_table_name('h5p_libraries') === 'wp_h5p_network_h5p_libraries');
check('network: restrictions table', H5P_Tables::build_full_db_table_name('h5p_libraries_restrictions') === 'wp_h5p_network_h5p_libraries_restrictions');
check('network: restricted column per blog', H5P_Library_Restrictions::restricted_column_sql('hl')
  === 'EXISTS(SELECT 1 FROM wp_h5p_network_h5p_libraries_restrictions hlr WHERE hlr.library_id = hl.id AND hlr.blog_id = 4)');

check('unknown table resolves like a blog table', H5P_Tables::build_full_db_table_name('h5p_contnets') === 'wp_4_h5p_contnets');
check('unknown table reported once', count($GLOBALS['h5p_unit_doing_it_wrong']) === 1);

// --- H5P_Mode: what differs between the modes

$local = new H5P_Mode_Local();
$network = new H5P_Mode_Network();
check('local manages libraries per blog', $local->manages_libraries_per_blog() === true);
check('network does not', $network->manages_libraries_per_blog() === false);
check('local capability source', $local->get_library_capability_source('manage_options') === 'manage_options');
check('network capability source', $network->get_library_capability_source('manage_options') === array('install_plugins', 'manage_network_plugins'));
check('local has no libraries URL override', $local->get_libraries_url_override() === null);
$files = array('scripts' => array((object) array('path' => '/libraries/X-1.0/x.js')));
$local->rewrite_asset_paths($files);
check('local leaves asset paths alone', $files['scripts'][0]->path === '/libraries/X-1.0/x.js');

echo ($failures === 0 ? "ALL {$passes} PASSED" : "{$failures} FAILED, {$passes} passed") . "\n";
exit($failures === 0 ? 0 : 1);
