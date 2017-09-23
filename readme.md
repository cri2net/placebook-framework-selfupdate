# ReadMe

Пакет предназначен для полуавтоматического обновления структуры БД для ядра проекта или сторонних пакетов.

Этот пакет является частью Placebook\Framework, однако, его можно использовать и для сторонних проектов. Также, его можно использовать не только для sql миграций, а и для любых других обратимых миграций.

## Принцип работы для ядра
В проекте присутствует папка для этого пакета. Например, /install
Путь к этой папке передан пакету:

```php
use \Placebook\Framework\Core\Install\SelfUpdate;
SelfUpdate::$installDir = ROOT . '/install';
```
В этой директории находится файл versions.json и custom_versions.json следующего содержимого:

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
Тут идёт описание версий. По умолчанию, имена классов находятся в namespace Placebook\Framework\Core\Install\, но можно указать другой namespace.
Версии ядра всегда будут увеличить только первое число версии и находиться в **versions.json**. Тогда конкретный сайт может взять себе актуальную версию ядра, например, 3.0.0, и увеличить версии как угодно, но не увеличивая первое число 3. И хранить свои версии в файле **custom_versions.json**. Пакет сделает слияние обоих файлов и сможет обновиться с нуля до максимальной версии ядра, а потом до максимальной версии конкретного проекта.
При этом, конкретный проект, который уже работает и имеет свои миграции, сможет обновить себе версию ядра, а с ней обновится и **versions.json**. Например, там появится версия 4.0.0. Тогда пакет обновит ядро, а дальше проект должен будет своим миграциям назначать версии >4.0.0
Ядро Placebook\Framework не будет сохранять свои версии в файле **custom_versions.json**, он только для пользователей фреймворка.
Таким образом, пакет будет хорошо и автоматически работать и с миграциями ядра, и проекта.

## Процесс обновления
В указанной папке находится файл **db_version.lock**
В нём хранится текущая версия ядра. Если файла нет, версия интерпретируется как 0

В этой же папке находится файл **updating.lock**. Если он существует, значит идёт обновление, тогда пакет не будет запускать обновление в другом потоке.
Таким образом, если миграция идёт продолжительное время, то только первый запрос (после заливки новых файлов) запустит миграцию. А остальные входящие запросы будут обрабатываться на той струкруте базы, что есть.
Пакет считывает все доступные версии и выстраивает их в порядке возрастания. От одной версии к другой нужно идти через все промежуточные.
Если обновление прервётся, то файл updating.lock останется, и больше обновление не запустится, пока не разобраться в ситуации вручную.



## Для сайтов

Обновление ядра:
```php
namespace Placebook\Framework\Core\Install;

SelfUpdate::$installDir = ROOT . '/install';
SelfUpdate::updateDbIfLessThen('6.0.0'); // обновление к конкретной версии
SelfUpdate::updateDbIfLessThen(SelfUpdate::getMaxVersion()); // обновление к максимальной версии


// updateDbIfLessThen обновляет только вверх. Если нужно откатиться вниз, для этого другой метод:
$current = SelfUpdate::getDbVersion();
SelfUpdate::updateFromTo($current, '0.0.7'); // обновление, которое будет работать и вниз
```

### Добавление миграции
- Сайту необходимо обернуть миграцию в класс, который реализовывает интерфейс *Placebook\Framework\Core\Install\MigrationInterface*
- Необходимо добавить название класса и namespace (если он не Placebook\Framework\Core\Install, который по умолчанию) в custom_versions.json под новой версией
- В классе правильно реализовать как миграцию вверх, так и откат миграции

### Пример класса миграции
```php
<?php

namespace Placebook\Framework\Core\Install;

use \Exception;
use cri2net\php_pdo_db\PDO_DB;

class Migration_sample implements MigrationInterface
{
    public static function up()
    {
        $pdo = PDO_DB::getPDO();
        try {
            $pdo->beginTransaction();
            // что-то полезное
            $pdo->commit();
        } catch (Exception $e) {
            // при неудаче откатываем транзакцию
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function down()
    {
        $pdo = PDO_DB::getPDO();
        try {
            $pdo->beginTransaction();
            // Откат обновления
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
```

## Для пакетов
Файл с версиями необходим только один, в custom_version.json нет необходимости. Рекомендуется иметь такую структуру:
- /vendor/vendor_name/package_name/versions.json
- /vendor/vendor_name/package_name/\*\*/  любые полезные классы, в том числе с миграциями

На сайте, раз есть модули, можно вынести работу с SelfUpdate в отдельный файл, который будет подключаться для всех запросов.
Предлагаемое содержимое:
```php
<?php
namespace Placebook\Framework\Core\Install;

use \Exception;

// обновление ядра
SelfUpdate::$installDir = PROTECTED_DIR . '/install';
SelfUpdate::updateDbIfLessThen(SelfUpdate::getMaxVersion());

// данные о модулях для обновления
$modules = [
    ['vendor' => 'vendor1', 'name' => 'package1'],
    ['vendor' => 'vendor2', 'name' => 'package2'],
];

try {
    foreach ($modules as $module) {
        
        try {

            $versionsDir = PROTECTED_DIR . "/vendor/{$module['vendor']}/" . $module['name']; // путь к папке vendor композера
            $versions = SelfUpdate::getVersions($versionsDir); // получаем версии пакета
            $max = SelfUpdate::getMaxVersion($versions); // определяем максимальную версию пакета
            SelfUpdate::updatePackage($max, $versionsDir, $module['vendor'], $module['name']); // обновляем
            
        } catch (Exception $e) {
        }

    }
} catch (Exception $e) {
}
```

Храниться установленные версии модулей будут по пути
install/modules/<vendor_name>/<package_name>.version.lock
Если модуль имеет миграции в БД и доступен к установке через composer, то при добавлении его как зависимость в проект, будет достаточно один раз добавить его в массив с модулями, и дальше можно обновлять его версии через composer, а изменения в структуре БД будут происходить автоматически
