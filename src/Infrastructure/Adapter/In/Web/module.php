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

use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Infrastructure\Context\Session;
use SP\Infrastructure\Crypt\Csrf;
use SP\Domain\Core\Bootstrap\BootstrapInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Infrastructure\Html\Services\MinifyCss;
use SP\Infrastructure\Html\Services\MinifyJs;
use SP\Infrastructure\File\FileCache;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Adapter\In\Web\Bootstrap;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountSearchData;
use SP\Infrastructure\Adapter\In\Web\Controllers\Resource\CssController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Resource\JsController;
use SP\Infrastructure\Adapter\In\Web\Init;

use function DI\add;
use function DI\autowire;
use function DI\factory;
use function DI\get;

return [
    'paths' => add([
                       [Path::VIEW, FileSystem::buildPath(APP_ROOT, 'public', 'themes')],
                       [Path::PLUGINS, FileSystem::buildPath(__DIR__, 'plugins')],
                   ]),
    BootstrapInterface::class => autowire(Bootstrap::class),
    ModuleInterface::class => autowire(Init::class),
    CssController::class => autowire(
        CssController::class
    )->constructorParameter('minify', autowire(MinifyCss::class)),
    JsController::class => autowire(
        JsController::class
    )->constructorParameter('minify', autowire(MinifyJs::class)),
    // Lazy: ConfigFile depends on Context but only uses it at save-time, while the
    // session handler depends on ConfigData. A lazy proxy lets the config load first
    // and breaks the ConfigData -> ConfigFile -> Context -> SessionHandler -> ConfigData cycle.
    Context::class => autowire(Session::class)->lazy(),
    CsrfHandler::class => autowire(Csrf::class)
        ->constructorParameter('context', get(Context::class)),
    AccountSearchData::class => autowire(AccountSearchData::class)
        ->constructorParameter(
            'fileCache',
            factory(static function (PathsContext $pathsContext) {
                return new FileCache(
                    FileSystem::buildPath($pathsContext[Path::CACHE], 'colors.cache')
                );
            })
        )
];
