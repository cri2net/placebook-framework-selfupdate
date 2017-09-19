<?php

namespace Placebook\Framework\Core\Install;

use \Exception;

/**
 * Интерфейс для миграций структуры БД.
 */
interface MigrationInterface
{
    /**
     * Этот метод применяет миграцию (повышает версию)
     * @return void
     */
    public static function up();
    
    /**
     * Этот метод откатывает миграцию (понижает версию)
     * @return void
     */
    public static function down();
}
