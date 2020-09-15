# ReadMe

The package is intended for semi-automatic updating of the database structure for the project core or third-party packages.

This package is part of the Placebook\Framework, but it can be used for any of your own projects. Also, it can be used not only for sql migrations, but also for any other reversible migrations.

## How it works for the kernel
The project contains a folder for this package. for example, /install
The path to this folder was passed to the package:

```php
use Placebook\Framework\Core\Install\SelfUpdate;

SelfUpdate::$installDir = ROOT . '/install';
```
This directory contains the versions.json file and custom_versions.json with the following content:

```json
{
    "details": {
        "0":     {"class": null},
        "1.0.0": {"class": "Migration_1"},
        "2.0.0": {"class": "Migration_2"},
        "3.0.0": {"class": "Migration_3", "namespace": "\\YourProject\\Test\\Migration"}
    }
}
```
Here is the version description. By default, class names are in namespace Placebook\Framework\Core\Install\, but another namespace can be specified.
Kernel versions will always increase only the first version number and be in **versions.json**. Then a specific site can take the current version of the kernel, eg, 3.0.0, and increase the versions as you like, but without increasing the major part of the version (3). And store your versions in a file **custom_versions.json**. The package will merge both files and will be able to upgrade from scratch to the maximum kernel version, and then to the maximum version of a specific project.
At the same time, a specific project that is already working and has its own migrations will be able to update its kernel version, and with it it will be updated and **versions.json**. For example, version 4.0.0 will appear there. Then the package will update the kernel, and then the project will have to assign versions >4.0.0 to its migrations
Placebook\Framework core will not save its versions to file **custom_versions.json**, he is only yours
This way, the package will work well and automatically with both kernel and project migrations.

## Update process
The specified folder contains the file **db_version.lock**
It stores the current version of the kernel. If there is no file, version is interpreted as 0

In the same folder there is a file **updating.lock**. If it exists, then the update is in progress, then the package will not start the update in another thread.
Thus, if the migration goes on for a long time, then only the first request (after uploading new files) will start the migration. And the rest of the incoming requests will be processed on the base structure that is.
The package reads all available versions and arranges them in ascending order. From one version to another, you need to go through all the intermediate ones.
If the update is interrupted, the updating.lock file will remain, and the update will not start again until you sort out the situation manually.



## For sites

Kernel update:
```php
namespace Placebook\Framework\Core\Install;

SelfUpdate::$installDir = ROOT . '/install';
SelfUpdate::updateDbIfLessThen('6.0.0'); // update to a specific version
SelfUpdate::updateDbIfLessThen(SelfUpdate::getMaxVersion()); // upgrade to maximum version


// updateDbIfLessThen updates only upwards. If you need to roll back down, there is another method for this:
$current = SelfUpdate::getDbVersion();
SelfUpdate::updateFromTo($current, '0.0.7'); // update that will work up and down (downgrade)
```

### Adding migration
- The site needs to wrap the migration in a class that implements the interface *Placebook\Framework\Core\Install\MigrationInterface*
- You need to add the class name and namespace (if it is not Placebook\Framework\Core\Install, which is the default) in custom_versions.json under the new version
- In the class, it is desirable to implement both upward migration and migration rollback

### Migration class example
```php
<?php

namespace Placebook\Framework\Core\Install;

use Exception;
use cri2net\php_pdo_db\PDO_DB;

class Migration_sample implements MigrationInterface
{
    public static function up()
    {
        $pdo = PDO_DB::getPDO();
        try {

            $pdo->beginTransaction();
            // something useful
            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function down()
    {
        $pdo = PDO_DB::getPDO();
        try {

            $pdo->beginTransaction();
            // Rollback update
            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
```

## For packages
Only one version file is needed, custom_versions.json is not needed. It is recommended to have this structure:

* /vendor/vendor_name/package_name/versions.json
* /vendor/vendor_name/package_name/\*\*/  any useful classes, including those with migrations

Since there are modules on the site, you can move the work with SelfUpdate into a separate file that will be connected for all requests.
Suggested content:
```php
<?php
namespace Placebook\Framework\Core\Install;

use Exception;

// kernel update
SelfUpdate::$installDir = PROTECTED_DIR . '/install';

// data about modules for updating
$modules = [
    ['vendor' => 'vendor1', 'name' => 'package1'],
    ['vendor' => 'vendor2', 'name' => 'package2'],
];

try {
    foreach ($modules as $module) {
        
        try {

            $versionsDir = PROTECTED_DIR . "/vendor/{$module['vendor']}/" . $module['name']; // path to vendor folder
            $versions = SelfUpdate::getVersions($versionsDir); // get package versions
            $max = SelfUpdate::getMaxVersion($versions); // get max version
            SelfUpdate::updatePackage($max, $versionsDir, $module['vendor'], $module['name']); // update
            
        } catch (Exception $e) {
        }

    }

    SelfUpdate::updateDbIfLessThen(SelfUpdate::getMaxVersion());

} catch (Exception $e) {
}
```

Installed versions of modules will be stored along the path
install/modules/<vendor_name>/<package_name>.version.lock
If a module has migrations to the database and is available for installation through composer, then when you add it as a dependency to the project, it will be enough to add it to the array with modules once, and then you can update its versions through composer, and changes in the database structure will occur automatically
