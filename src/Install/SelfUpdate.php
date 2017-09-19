<?php

namespace Placebook\Framework\Core\Install;

use \Exception;

class SelfUpdate
{
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

        self::$installDir = rtrim(self::$installDir, '/\\');
    }

    /**
     * Миграция на указанную версию
     * @param  string $from_version PHP-стандартизированная текущая версия структуры БД
     * @param  string $to_version   PHP-стандартизированная требуемая версия структуры БД
     * @return void
     */
    public static function updateFromTo($from_version, $to_version)
    {
        if (version_compare($from_version, $to_version) == 0) {
            return true;
        }

        self::checkInit();

        if (!file_exists(self::$installDir . '/versions.json')) {
            return false;
        }

        if (self::isUpdating()) {
            return false;
        }

        self::setUpdating();

        try {
            $rules = self::getVersions();

            if (isset($rules->details->$from_version)) {
                
                $need_version = (version_compare($from_version, $to_version) == -1)
                    ? self::getNext($from_version)
                    : self::getPrev($from_version);

                $need_run = (version_compare($from_version, $to_version) == -1)
                    ? self::getNext($from_version)
                    : $from_version;

                $className = __NAMESPACE__ . '\\' . $rules->details->$need_run->class;
                $migration = new $className;

                if (!is_a($migration, __NAMESPACE__ . '\MigrationInterface')) {
                    throw new Exception('Migration is not implement ' . __NAMESPACE__ . '\MigrationInterface');
                }

                if (version_compare($from_version, $to_version) == -1) {
                    $migration->up();
                } else {
                    $migration->down();
                }

                self::setDbVersion($need_version);
                self::unsetUpdating();

                // рекурсивно обновляемся до следующей версии в цепочке, пока не дойдём до требуемой
                self::updateFromTo($need_version, $to_version);
            }
            
        } catch (Exception $e) {
            // die($e->getMessage());
        }
    }

    /**
     * Возвращает следующую по возрастанию версию
     * @param  string $version PHP-стандартизированная версия
     * @return string|null     Следующая версия
     */
    public static function getNext($version)
    {
        $rules = self::getVersions();
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
     * @param  string $version PHP-стандартизированная версия
     * @return string|null     Предыдущая версия
     */
    public static function getPrev($version)
    {
        $rules = self::getVersions();
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
     * @return \StdClass объект с данными
     */
    public static function getVersions()
    {
        self::checkInit();

        $rules = file_get_contents(self::$installDir . '/versions.json');
        $rules = json_decode($rules, true);

        if (file_exists(self::$installDir . '/custom_versions.json')) {

            $custom = file_get_contents(self::$installDir . '/custom_versions.json');
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
        if (!file_exists(self::$installDir . '/db_version.lock')) {
            return '0';
        }

        return file_get_contents(self::$installDir . '/db_version.lock');
    }

    /**
     * Записывает в файл новую версию Базы Данных
     * @param string $version PHP-стандартизированная версия структуры БД
     * @return  void
     */
    public static function setDbVersion($version)
    {
        self::checkInit();
        file_put_contents(self::$installDir . '/db_version.lock', $version);
    }
}
