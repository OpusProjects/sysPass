<?php
declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Infrastructure\Bootstrap\BootstrapBase;
use SP\Infrastructure\Bootstrap\RouteContext;
use Symfony\Component\Yaml\Yaml;

/**
 * A route is a string, and nothing checks one until somebody clicks it.
 *
 * Every link and every button in the application is a route the front-end names — either written
 * into a template, or read out of `resources/actions.yaml` through `Acl::getRouteFor()`. The web
 * entry point resolves it by *convention*: `account/saveDelete` becomes
 * `Controllers\Account\SaveDeleteController::saveDeleteAction()`. Nothing links the name to the
 * class, so renaming or moving a controller leaves the name behind, pointing at nothing, and the
 * button that carries it silently does nothing. Three of those were found and fixed by hand
 * (Remove Account, the favourite star, the version restore); this is what makes finding the fourth
 * not depend on somebody clicking it.
 *
 * Resolving is only half of it. `Bootstrap::getMethod()` will refuse a method that does not return
 * an ActionResponse or does not carry `#[Action]`, so a controller that exists but breaks the
 * dispatch contract is just as dead — with a 500 instead of a silence. Both halves are asserted
 * here, against the same rules the entry point applies:
 *
 *  - the route is split by `RouteContext`, the class named by `BootstrapBase::getClassFor()`,
 *  - and the method checked the way `Bootstrap::getMethod()` checks it.
 *
 * @see \SP\Infrastructure\Adapter\In\Web\Bootstrap::getMethod()
 */
#[Group('unitary')]
class RoutesAreDispatchableTest extends TestCase
{
    /**
     * Actions whose route the application never hands out, and which resolve to nothing.
     *
     * These are ACL action ids that exist so permissions can be expressed, carrying a `route:`
     * left over from before the rewrite split one controller per action. Nothing calls
     * `getRouteFor()` for any of them, so no link in the application is built from these strings
     * and none of them is reachable — which is why they are listed rather than fixed: giving each
     * a controller would be inventing a feature, and deleting the actions would drop the
     * permissions they name.
     *
     * The list is what keeps this test honest. Anything *not* on it must resolve, so a new stale
     * route fails here, and an entry that gets a controller has to be removed from the list.
     */
    private const KNOWN_UNROUTED = [
        'ACCOUNTMGR',              // accountManager/index
        'ACCOUNTMGR_HISTORY',      // accountHistoryManager/index
        'ACCOUNTMGR_VIEW',         // accountManager/view
        'ACCOUNT_EDIT_RESTORE',    // account/restore
        'ACCOUNT_FAVORITE',        // favorite/index
        'ACCOUNT_FAVORITE_VIEW',   // favorite/view
        'ACCOUNT_FILE',            // account/listFile
        'AUTHTOKEN',               // authToken/index
        'CATEGORY',                // category/index
        'CLIENT',                  // client/index
        'CONFIG_ACCOUNT',          // account/config
        'CONFIG_BACKUP',           // backup/config
        'CONFIG_BACKUP_RUN',       // backup/backup
        'CONFIG_CRYPT',            // encryption/config
        'CONFIG_CRYPT_REFRESH',    // encryption/updateHash
        'CONFIG_CRYPT_TEMPPASS',   // encryption/createTempPass
        'CONFIG_EXPORT',           // export/config
        'CONFIG_EXPORT_RUN',       // export/export
        'CONFIG_GENERAL',          // configManager/general
        'CONFIG_IMPORT',           // import/config
        'CONFIG_IMPORT_CSV',       // import/csv
        'CONFIG_IMPORT_XML',       // import/xml
        'CONFIG_LDAP',             // ldap/config
        'CONFIG_LDAP_SYNC',        // ldap/sync
        'CONFIG_MAIL',             // mail/config
        'CONFIG_WIKI',             // wiki/config
        'CUSTOMFIELD',             // customField/index
        'FILE',                    // file/index
        'FILE_DELETE',             // file/delete
        'FILE_DOWNLOAD',           // file/download
        'FILE_SEARCH',             // file/search
        'FILE_UPLOAD',             // file/upload
        'FILE_VIEW',               // file/view
        'GROUP',                   // group/index
        'ITEMPRESET',              // itemPreset/index
        'PLUGIN_CREATE',           // plugin/create
        'PROFILE',                 // profile/index
        'PUBLICLINK',              // publicLink/index
        'TAG',                     // tag/index
        'TRACK',                   // track/index
        'USER',                    // user/index
        'USERSETTINGS_GENERAL',    // userSettings/general
        'WIKI',                    // wiki/index
        'WIKI_CREATE',             // wiki/create
        'WIKI_DELETE',             // wiki/delete
        'WIKI_EDIT',               // wiki/edit
        'WIKI_VIEW',               // wiki/view
    ];

    /**
     * Every route the application hands out has to resolve to a method the entry point will
     * dispatch. This is the set that matters: an action id reaches a link only by being passed to
     * `getRouteFor()`, so these are exactly the strings that end up in the page.
     */
    #[Test]
    #[DataProvider('routesTheApplicationHandsOut')]
    public function aRouteTheApplicationHandsOutIsDispatchable(string $action, string $route): void
    {
        $this->assertRouteIsDispatchable($route, sprintf('%s (%s)', $route, $action));
    }

    /**
     * And so does every route written straight into a template, which the front-end posts back
     * verbatim without anything in PHP ever having seen it.
     */
    #[Test]
    #[DataProvider('routesTheTemplatesName')]
    public function aRouteATemplateNamesIsDispatchable(string $route, string $where): void
    {
        $this->assertRouteIsDispatchable($route, sprintf('%s (named in %s)', $route, $where));
    }

    /**
     * The list above is only meaningful while every name on it is real and still unrouted. An
     * entry for an action that has since been given a controller would quietly excuse it forever.
     */
    #[Test]
    public function theUnroutedListNamesOnlyActionsThatAreStillUnrouted(): void
    {
        $constants = (new ReflectionClass(AclActionsInterface::class))->getConstants();

        foreach (self::KNOWN_UNROUTED as $action) {
            self::assertArrayHasKey($action, $constants, sprintf('"%s" is not an ACL action', $action));

            $route = self::routes()[$constants[$action]] ?? null;
            self::assertNotNull($route, sprintf('"%s" has no route at all, so it does not belong here', $action));

            self::assertFalse(
                self::resolves($route),
                sprintf('"%s" resolves now — remove it from KNOWN_UNROUTED', $action)
            );
        }
    }

    /**
     * Every action id whose route the source asks for, paired with the route
     * `resources/actions.yaml` gives it — both through `Acl::getRouteFor()` and through the
     * `getActionById(...)->getRoute()` the API adapters use to build their links.
     *
     * Nothing is excluded here, KNOWN_UNROUTED included: the moment one of those is handed out it
     * becomes a live link, and this is where that has to be caught.
     *
     * @return array<string, array{string, string}>
     */
    public static function routesTheApplicationHandsOut(): array
    {
        $constants = (new ReflectionClass(AclActionsInterface::class))->getConstants();
        $routes = self::routes();
        $cases = [];

        foreach (self::sourceFiles(['php', 'inc']) as $file) {
            $source = (string)file_get_contents($file);

            preg_match_all(
                '/(?:getRouteFor|getActionById)\(\s*AclActionsInterface::(\w+)/',
                $source,
                $matches
            );

            foreach ($matches[1] as $action) {
                if (!isset($constants[$action])) {
                    continue;
                }

                $route = $routes[$constants[$action]] ?? null;

                if ($route !== null) {
                    $cases[$action] = [$action, $route];
                }
            }
        }

        return $cases;
    }

    /**
     * Every literal route written into the theme's templates. The dynamic ones — where the value
     * is a PHP expression — are covered by the provider above, since that is where they come from.
     *
     * @return array<string, array{string, string}>
     */
    public static function routesTheTemplatesName(): array
    {
        $cases = [];

        foreach (self::sourceFiles(['inc'], REAL_APP_ROOT . '/public/themes') as $file) {
            preg_match_all(
                '/data-action-route="([a-zA-Z]+\/[a-zA-Z]+)"/',
                (string)file_get_contents($file),
                $matches
            );

            foreach ($matches[1] as $route) {
                $cases[$route] ??= [$route, basename(dirname($file)) . '/' . basename($file)];
            }
        }

        return $cases;
    }

    private function assertRouteIsDispatchable(string $route, string $what): void
    {
        $routeContextData = RouteContext::getRouteContextData($route);

        $controllerClass = (new ReflectionMethod(BootstrapBase::class, 'getClassFor'))
            ->invoke(null, 'web', $routeContextData->controller, $routeContextData->actionName);

        self::assertTrue(class_exists($controllerClass), sprintf('%s: no class %s', $what, $controllerClass));
        self::assertTrue(
            method_exists($controllerClass, $routeContextData->methodName),
            sprintf('%s: %s has no %s()', $what, $controllerClass, $routeContextData->methodName)
        );

        // The three things Bootstrap::getMethod() insists on before it will call anything.
        $method = new ReflectionMethod($controllerClass, $routeContextData->methodName);
        $returnType = $method->getReturnType();

        self::assertTrue($method->isPublic(), sprintf('%s: %s is not public', $what, $routeContextData->methodName));
        self::assertInstanceOf(ReflectionNamedType::class, $returnType, $what);
        self::assertSame(ActionResponse::class, $returnType->getName(), sprintf('%s: wrong return type', $what));
        self::assertNotEmpty($method->getAttributes(Action::class), sprintf('%s: no #[Action] attribute', $what));
    }

    /**
     * @return array<int, string> action id => route, as the application reads them
     */
    private static function routes(): array
    {
        static $routes;

        if ($routes === null) {
            $routes = [];

            foreach (Yaml::parseFile(REAL_APP_ROOT . '/resources/actions.yaml')['actions'] as $action) {
                if (isset($action['id'], $action['route'])) {
                    $routes[(int)$action['id']] = $action['route'];
                }
            }
        }

        return $routes;
    }

    private static function resolves(string $route): bool
    {
        $routeContextData = RouteContext::getRouteContextData($route);

        $controllerClass = (new ReflectionMethod(BootstrapBase::class, 'getClassFor'))
            ->invoke(null, 'web', $routeContextData->controller, $routeContextData->actionName);

        return class_exists($controllerClass) && method_exists($controllerClass, $routeContextData->methodName);
    }

    /**
     * @param string[] $extensions
     * @return iterable<string>
     */
    private static function sourceFiles(array $extensions, string $path = REAL_APP_ROOT . '/src'): iterable
    {
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                yield $file->getPathname();
            }
        }
    }
}
