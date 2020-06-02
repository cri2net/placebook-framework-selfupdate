<?php

namespace Placebook\Framework\Core\Install;

use \Exception;

class SelfUpdate
{
    /**
     * Название файла, в котором хранится текущая версия БД ядра
     */
    const DB_VERSION_FILE = 'db_version.lock';

    /**
     * Путь к папке с данными
     */
    public static $installDir;

    /**
     * Метод сравнивает текущую версию БД с требуемой и запускает обновление при необходимости
     * @param  string $version PHP-стандартизированная версия структуры БД
     * @return void
     */
    public static function updateDbIfLessThen($version)
    {
        $current = self::getDbVersion();

        if (version_compare($current, $version, '>=')) {
            return;
        }

        self::updateFromTo($current, $version);
    }

    /**
     * Проверяет установлен ли в статическую переменную путь к рабочей папке модуля.
     * Запускается перед работой с этой папкой
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
     * Создаёт директорию на диске
     * @return void
     */
    private static function createFolder($path)
    {
        if (!file_exists($path)) {
            mkdir($path, 0775, true);
        }
    }

    /**
     * Миграция на указанную версию
     * @param  string $from_version PHP-стандартизированная текущая версия структуры БД
     * @param  string $to_version   PHP-стандартизированная требуемая версия структуры БД
     * @param  string $rulesDir     Путь к папке с версиями. OPTIONAL
     * @param  string $vendor       Название производителя пакета. OPTIONAL
     * @param  string $package_name Название пакета. OPTIONAL
     * @return void
     */
    public static function updateFromTo($from_version, $to_version, $rulesDir = null, $vendor = null, $package_name = null)
    {
        if (version_compare($from_version, $to_version) == 0) {
            return $to_version;
        }

        if (self::isUpdating()) {
            return false;
        }
        self::setUpdating();

        try {
            $rules = self::getVersions($rulesDir);

            do {

                if (isset($rules->details->$from_version)) {
                    
                    $need_version = (version_compare($from_version, $to_version) == -1)
                        ? self::getNext($from_version, $rulesDir)
                        : self::getPrev($from_version, $rulesDir);

                    $need_run = (version_compare($from_version, $to_version) == -1)
                        ? self::getNext($from_version, $rulesDir)
                        : $from_version;

                    $namespace = (isset($rules->details->$need_run->namespace))
                        ? $rules->details->$need_run->namespace
                        : __NAMESPACE__;
                    $className = $namespace . '\\' . $rules->details->$need_run->class;
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
            
            return false;
        }
    }

    /**
     * Возвращает следующую по возрастанию версию
     * @param  string $version  PHP-стандартизированная версия
     * @param  string $rulesDir Путь к папке с версиями. OPTIONAL
     * @return string|null      Следующая версия
     */
    public static function getNext($version, $rulesDir = null)
    {
        $rules = self::getVersions($rulesDir);
        $rules = json_decode(json_encode($rules), true); // преобразование в массив
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
     * Возвращает предыдущую по возрастанию версию
     * @param  string $version  PHP-стандартизированная версия
     * @param  string $rulesDir Путь к папке с версиями. OPTIONAL
     * @return string|null      Предыдущая версия
     */
    public static function getPrev($version, $rulesDir = null)
    {
        $rules = self::getVersions($rulesDir);
        $rules = json_decode(json_encode($rules), true); // преобразование в массив
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
     * Получение данных о доступных версиях (обновлениях)
     *
     * Возвращает результат слияния versions.json с custom_versions.json, если последний существует
     *
     * @param  string $dir Путь к папке, где лежат версии
     * @return \StdClass объект с данными
     */
    public static function getVersions($dir = null)
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

            // делаем слияние
            $rules['details'] = array_merge($rules['details'], $custom['details']);
        }

        // преобразование в объект
        $rules = json_decode(json_encode($rules));
        return $rules;
    }

    /**
     * Проверка: запущено ли в данный момент обновление
     * @return boolean
     */
    public static function isUpdating()
    {
        self::checkInit();
        return file_exists(self::$installDir . '/updating.lock');
    }

    /**
     * Создание .lock файла для дальнейшего определения, что обновление БД запущено
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
     * Удаление .lock файла после обновление БД
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
     * Получение текущей версии Базы Данных
     * @return string PHP-стандартизированная версия структуры БД
     */
    public static function getDbVersion()
    {
        self::checkInit();
        return self::getVersionFromFile(self::$installDir . '/' . self::DB_VERSION_FILE);
    }

    /**
     * Записывает в файл новую версию Базы Данных
     * @param string $version PHP-стандартизированная версия структуры БД
     * @return  void
     */
    public static function setDbVersion($version)
    {
        self::checkInit();
        file_put_contents(self::$installDir . '/' . self::DB_VERSION_FILE, $version);
    }

    /**
     * Получение текущей версии из файла
     * @param  string $file Путь к файлу с сохранённой версией
     * @return string PHP-стандартизированная версия структуры БД
     */
    public static function getVersionFromFile($file)
    {
        if (!file_exists($file)) {
            return '0';
        }

        return file_get_contents($file);
    }

    /**
     * Получение установленной версии Пакета
     * @param string $vendor       Название производителя пакета
     * @param string $package_name Название пакета
     * @return  void
     */
    public static function getPackageVersion($vendor, $package_name)
    {
        self::checkInit();
        $path = self::$installDir . "/modules/$vendor/{$package_name}.version.lock";
        return self::getVersionFromFile($path);
    }

    /**
     * Записывает в файл новую версию Пакета
     * @param string $version      PHP-стандартизированная версия структуры БД
     * @param string $vendor       Название производителя пакета
     * @param string $package_name Название пакета
     * @return  void
     */
    public static function setPackageVersion($version, $vendor, $package_name)
    {
        self::checkInit();
        $dir = self::$installDir . "/modules/$vendor/";
        self::createFolder($dir);
        file_put_contents($dir . "{$package_name}.version.lock", $version);
    }

    /**
     * Обновляет версию БД пакета
     * @param string $version      PHP-стандартизированная версия структуры БД
     * @param  string $rulesDir     Путь к папке с версиями. OPTIONAL
     * @param string $vendor       Название производителя пакета
     * @param string $package_name Название пакета
     * @return  void
     */
    public static function updatePackage($version, $rulesDir, $vendor, $package_name)
    {
        $currentVersion = self::getPackageVersion($vendor, $package_name);
        self::updateFromTo($currentVersion, $version, $rulesDir, $vendor, $package_name);
    }

    /**
     * Возвращает максимальную версию
     * @param  array $rules Данные о версиях, если не указано, то будут использованы версии ядра. OPTIONAL
     * @return string|null  Максимальная версия
     */
    public static function getMaxVersion($rules = null)
    {
        if ($rules === null) {
            $rules = self::getVersions();
        }
        $rules = json_decode(json_encode($rules), true); // преобразование в массив
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
