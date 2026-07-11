<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Infrastructure\File\FileSystem;

use function SP\getFromEnv;
use function SP\initModule;
use function SP\processException;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
}

require FileSystem::buildPath(APP_ROOT, 'vendor', 'autoload.php');

define('APP_PATH', APP_ROOT);

// Functions.php defines MODULES_PATH/LOG_FILE from APP_PATH at load time, so it
// must be required after APP_PATH is defined.
require __DIR__ . '/Infrastructure/Functions.php';

$dotenv = Dotenv::createImmutable(APP_ROOT);
$dotenv->load();

defined('APP_MODULE') || define('APP_MODULE', 'web');

define('DEBUG', getFromEnv('DEBUG', false));

try {
    $moduleDefinitions = initModule(APP_MODULE);

    $containerBuilder = new ContainerBuilder();

    if (!DEBUG) {
        $cachePath = getFromEnv('CACHE_PATH', FileSystem::buildPath(APP_PATH, 'var', 'cache'));
        $containerBuilder->enableCompilation($cachePath);
        $containerBuilder->writeProxiesToFile(true, FileSystem::buildPath($cachePath, 'proxies'));
    }

    return $containerBuilder
        ->addDefinitions(
            // Generic Domain Ports->Services wildcard auto-wiring must come first so the
            // explicit, specially-constructed entries in CoreDefinitions (e.g. ConfigFileService)
            // override the wildcard fallback. php-di gives later definition sources precedence,
            // so Core after Domain. This mirrors the ordering the integration suite builds with.
            DomainDefinitions::getDefinitions(),
            CoreDefinitions::getDefinitions(APP_ROOT, APP_MODULE),
            $moduleDefinitions
        )
        ->build();
} catch (Throwable $e) {
    processException($e);

    die($e->getMessage());
}
