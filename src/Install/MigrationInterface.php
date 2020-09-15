<?php

namespace Placebook\Framework\Core\Install;

use Exception;

/**
 * Interface for migrating the database structure.
 */
interface MigrationInterface
{
    /**
     * This method applies migration (update)
     * @return void
     */
    public static function up();
    
    /**
     * This method rolls back the migration (downgrade)
     * @return void
     */
    public static function down();
}
