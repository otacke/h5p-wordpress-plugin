<?php

trait H5PUtils {
    /**
     * Recursively copy directory.
     *
     * @param string $source      Source directory path.
     * @param string $destination Target directory path.
     * @return bool True on success, false on failure.
     */
    public static function copyDirectory($source, $destination) {
        if ($source === $destination) {
            return true;
        }

        $base_name = basename($source);
        $dest_path = "{$destination}/{$base_name}";

        $dir = opendir($source);

        if ($dir === false) {
            return false;
        }

        if (!is_dir($dest_path)) {
            mkdir($dest_path, 0755, true);
        }

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir("{$source}/{$file}")) {
                if (!self::copyDirectory("{$source}/{$file}", "{$dest_path}")) {
                    return false;
                }
            }
            else {
                if (!copy("{$source}/{$file}", "{$dest_path}/{$file}")) {
                    return false;
                }
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Check whether version $b is the latest patch for its major.minor version
     * among all versions seen so far.
     *
     * Returns true if $b has a different major or minor version than $a (meaning
     * it is a different library variant), or if it shares the same major.minor
     * but has a newer patch version.
     *
     * @param string $a Existing version string (e.g. "1.4.5").
     * @param string $b Current version string (e.g. "1.4.3").
     * @return bool
     */
    public static function isLatestPatchVersion($a, $b) {
        $a_parts = explode('.', $a);
        $b_parts = explode('.', $b);

        return $a_parts[0] == $b_parts[0] && $a_parts[1] == $b_parts[1] && $a_parts[2] < $b_parts[2];
    }

    /**
     * Parse library.json and return library metadata.
     *
     * @param string $path Path to library.json.
     * @return array|null  Associative array with machineName, majorVersion,
     *                     minorVersion, patchVersion; or null on failure.
     */
    public static function readVersionedInfoFromLibraryJson($path) {
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            return null;
        }

        $machine_name = $data['machineName'] ?? null;
        if ($machine_name === null) {
            return null;
        }

        if (!isset($data['majorVersion']) || !isset($data['minorVersion']) || !isset($data['patchVersion'])) {
            return null;
        }

        return array(
            'machineName'   => $machine_name,
            'majorVersion'  => $data['majorVersion'],
            'minorVersion'  => $data['minorVersion'],
            'patchVersion'  => $data['patchVersion'],
        );
    }

    /**
     * Filters and sanitizes input, replacing FILTER_SANITIZE_STRING.
     *
     * @param string $var_name Name of the variable to sanitize.
     * @return string Sanitized value.
     */
    public function sanitize_input($var_name): string
    {
        $var_name = filter_input(INPUT_GET, $var_name);
        return htmlspecialchars($var_name ?? '', ENT_QUOTES, 'UTF-8');
    }
}
