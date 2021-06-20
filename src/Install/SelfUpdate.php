<?php

namespace Placebook\Framework\Core\Install;

use Exception;

class SelfUpdate
{
    /**
     * Filename that stores the current version of the kernel database
     */
    const DB_VERSION_FILE = 'db_version.lock';

    /**
     * Path to data folder
     */
    public static $installDir;

    /**
     * Method compares the current version of the database with the required one and starts the update if necessary
     * @param  string $version PHP standardized version of DB structure
     * @return void
     */
    public static function updateDbIfLessThen(string $version)
    {
        $current = self::getDbVersion();

        if (version_compare($current, $version, '>=')) {
            return;
        }

        self::updateFromTo($current, $version);
    }

    /**
     * Checks if the path to the working folder of the module is set in a static variable.
     * Runs before working with this folder
     * @return void
     */
    private static function checkInit()
    {
        if (empty(self::$installDir)) {
            throw new Exception('Please set path to folder for json config files in SelfUpdate::$installDir');
        }

        self::createFolder(self::$installDir);
        self::$installDir = rtrim(self::$installDir, '/\\');
    }

    /**
     * Creates a directory on disk
     * @return void
     */
    private static function createFolder(string $path)
    {
        if (!file_exists($path)) {
            mkdir($path, 0775, true);
        }
    }

    /**
     * Migrating to a specified version
     * @param  string $from_version PHP-standardized current version of DB structure
     * @param  string $to_version   PHP standardized required version of DB structure
     * @param  string $rulesDir     Path to the folder with versions. OPTIONAL
     * @param  string $vendor       Package vendor name. OPTIONAL
     * @param  string $package_name Package name. OPTIONAL
     * @return void
     */
    public static function updateFromTo(
        string $from_version,
        string $to_version,
        string $rulesDir = null,
        string $vendor = null,
        string $package_name = null
    )
    {
        if (version_compare($from_version, $to_version) == 0) {
            return;
        }

        if (self::isUpdating()) {
            return;
        }
        self::setUpdating();

        try {
            $rules = self::getVersions($rulesDir);

            do {

                if (isset($rules['details'][$from_version])) {
                    
                    $need_version = (version_compare($from_version, $to_version) == -1)
                        ? self::getNext($from_version, $rulesDir)
                        : self::getPrev($from_version, $rulesDir);

                    $need_run = (version_compare($from_version, $to_version) == -1)
                        ? self::getNext($from_version, $rulesDir)
                        : $from_version;

                    $namespace = $rules['details'][$need_run]['namespace'] ?? __NAMESPACE__;
                    $className = $namespace . '\\' . $rules['details'][$need_run]['class'];
                    $migration = new $className;

                    if (!is_a($migration, __NAMESPACE__ . '\MigrationInterface')) {
                        throw new Exception('Migration is not implement ' . __NAMESPACE__ . '\MigrationInterface');
                    }

                    if (version_compare($from_version, $to_version) == -1) {
                        $migration->up();
                    } else {
                        $migration->down();
                    }

                    if (!is_null($vendor) && !is_null($package_name)) {
                        self::setPackageVersion($need_version, $vendor, $package_name);
                    } else {
                        self::setDbVersion($need_version);
                    }
                    $from_version = $need_version;
                } else {
                    throw new Exception("Requested current version $from_version not present in rules (versions.json & custom_versions.json)");
                }
            } while (version_compare($from_version, $to_version) != 0);

            self::unsetUpdating();
            
        } catch (Exception $e) {

            $message = date('Y.m.d H:i:s');
            $message .= ' error in ' . __CLASS__ . "\r\n";
            $message .= "update from $from_version to $to_version\r\n";
            $message .= (empty($package_name)) ? '' : "package name = $package_name\r\n";
            @error_log($message . var_export($e, true) . "\r\n\r\n\r\n", 3, self::$installDir . '/update-errors.log');
        }
    }

    /**
     * Returns the next ascending version
     * @param  string $version   PHP-standardized version
     * @param  ?string $rulesDir Path to the folder with versions. OPTIONAL
     * @return ?string           Next version
     */
    public static function getNext(string $version, string $rulesDir = null) : ?string
    {
        $rules = self::getVersions($rulesDir);
        $min = null;

        foreach ($rules['details'] as $key => $value) {
            
            if (version_compare($key, $version, '>')) {
                
                if ($min === null) {
                    $min = $key;
                } elseif (version_compare($key, $min, '<')) {
                    $min = $key;
                }
            }
        }

        return $min;
    }

    /**
     * Returns the previous ascending version
     * @param  string $version  PHP-standardized version
     * @param  string $rulesDir Path to the folder with versions. OPTIONAL
     * @return string|null      Previous version
     */
    public static function getPrev(string $version, string $rulesDir = null) : ?string
    {
        $rules = self::getVersions($rulesDir);
        $max = null;

        foreach ($rules['details'] as $key => $value) {

            if (version_compare($key, $version, '<')) {
                if ($max === null) {
                    $max = $key;
                } elseif (version_compare($key, $max, '>')) {
                    $max = $key;
                }
            }
        }

        return $max;
    }

    /**
     * Obtaining data on available versions (updates)
     *
     * Returns the result of merging versions.json with custom_versions.json, if the latter exists
     *
     * @param  string $dir The path to the folder where the versions are located
     * @return array Versions data
     */
    public static function getVersions(string $dir = null) : array
    {
        if ($dir === null) {
            self::checkInit();
            $dir = self::$installDir;
        }

        if (!file_exists($dir . '/versions.json')) {
            throw new Exception($dir . '/versions.json Not Found');
        }

        $rules = file_get_contents($dir . '/versions.json');
        $rules = json_decode($rules, true);

        if (file_exists($dir . '/custom_versions.json')) {

            $custom = file_get_contents($dir . '/custom_versions.json');
            $custom = json_decode($custom, true);

            $rules['details'] = array_merge($rules['details'], $custom['details']);
        }

        return $rules;
    }

    /**
     * Checking whether the update is currently running
     * @return boolean
     */
    public static function isUpdating() : bool
    {
        self::checkInit();
        return file_exists(self::$installDir . '/updating.lock');
    }

    /**
     * Create a .lock file to further determine that a database update has started
     * @return void
     */
    public static function setUpdating()
    {
        self::checkInit();
        file_put_contents(self::$installDir . '/updating.lock', '');

        if (!file_exists(self::$installDir . '/updating.lock')) {
            throw new Exception("Install folder is not writable");
        }
    }

    /**
     * Deleting .lock file after database update
     * @return void
     */
    public static function unsetUpdating()
    {
        self::checkInit();
        if (file_exists(self::$installDir . '/updating.lock')) {
            unlink(self::$installDir . '/updating.lock');
        }
    }

    /**
     * Getting the current version of the kernel
     * @return string PHP standardized version of DB structure
     */
    public static function getDbVersion()
    {
        self::checkInit();
        return self::getVersionFromFile(self::$installDir . '/' . self::DB_VERSION_FILE);
    }

    /**
     * Writes a new version of the kernel to a file
     * @param string $version PHP standardized version of DB structure
     * @return  void
     */
    public static function setDbVersion(string $version)
    {
        self::checkInit();
        file_put_contents(self::$installDir . '/' . self::DB_VERSION_FILE, $version);
    }

    /**
     * Getting the current version from a file
     * @param  string $file The path to the file with the saved version
     * @return string PHP standardized version of DB structure
     */
    public static function getVersionFromFile(string $file) : string
    {
        if (!file_exists($file)) {
            return '0';
        }

        return file_get_contents($file);
    }

    /**
     * Obtaining the installed version of the Package
     * @param string $vendor       Package vendor name
     * @param string $package_name Package name
     * @return string PHP standardized version of Package's DB structure
     */
    public static function getPackageVersion(string $vendor, string $package_name)
    {
        self::checkInit();
        $path = self::$installDir . "/modules/$vendor/{$package_name}.version.lock";
        return self::getVersionFromFile($path);
    }

    /**
     * Writes a new version of the Package to a file
     * @param string $version      PHP standardized version of DB structure
     * @param string $vendor       Package vendor name
     * @param string $package_name Package name
     * @return void
     */
    public static function setPackageVersion(string $version, string $vendor, string $package_name)
    {
        self::checkInit();
        $dir = self::$installDir . "/modules/$vendor/";
        self::createFolder($dir);
        file_put_contents($dir . "{$package_name}.version.lock", $version);
    }

    /**
     * Updates the DB version of the package
     * @param string $version      PHP standardized version of DB structure
     * @param string $rulesDir     Path to the folder with versions. OPTIONAL
     * @param string $vendor       Package vendor name
     * @param string $package_name Package name
     * @return void
     */
    public static function updatePackage(string $version, string $rulesDir, string $vendor, string $package_name)
    {
        $currentVersion = self::getPackageVersion($vendor, $package_name);
        self::updateFromTo($currentVersion, $version, $rulesDir, $vendor, $package_name);
    }

    /**
     * Returns the maximum version
     * @param  mixed $rules Version data, if not specified, kernel versions will be used. OPTIONAL
     * @return string|null  Maximum version
     */
    public static function getMaxVersion($rules = null) : ?string
    {
        if ($rules === null) {
            $rules = self::getVersions();
        }
        $max = null;

        foreach ($rules['details'] as $key => $value) {

            if ($max === null) {
                $max = $key;
            } elseif (version_compare($key, $max, '>')) {
                $max = $key;
            }
        }

        return $max;
    }
}
