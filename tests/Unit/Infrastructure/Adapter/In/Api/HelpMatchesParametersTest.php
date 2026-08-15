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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Domain\Api\Ports\HelpInterface;

/**
 * What the API says it takes, and what it actually reads.
 *
 * There is no schema and no route definition listing an endpoint's parameters: a controller simply
 * asks `getParam*('name', $required)` for whatever it wants, and a separate `*Help` class states
 * the same list in prose. Nothing holds the two together. They are read together exactly once, at
 * the moment a call goes wrong — `Api::getParam()` answers a missing required parameter with a
 * *"Wrong parameters"* 400 whose hint is the help for that action — so a caller's only description
 * of an endpoint is the one place the application never checks.
 *
 * Drift is therefore silent and one-directional in its harm: a parameter the controller reads but
 * nobody documented cannot be discovered, and a parameter documented but never read is worse — the
 * caller sends it, gets a success, and believes it did something. `account/viewPass` documented
 * `details` ("Send details in the response") since 3.2 and has never read it.
 *
 * So this asserts the two halves agree, per action:
 *
 *  - every parameter the controller reads is documented,
 *  - every documented parameter is one the controller reads,
 *  - and required is required on both sides.
 *
 * It is a source-level check because that is where both halves live; there is no runtime object
 * that knows an endpoint's parameters.
 */
#[Group('unitary')]
class HelpMatchesParametersTest extends TestCase
{
    private const CONTROLLERS = REAL_APP_ROOT . '/src/Infrastructure/Adapter/In/Api/Controllers';

    /**
     * `tokenPass` is the token's own password, read by `ApiService::getMasterPass()` rather than by
     * the controller, so it is documented for the actions that need the vault opened and never
     * appears as a `getParam*()` call. It is the one name the two halves cannot be expected to
     * agree on.
     */
    private const NOT_A_CONTROLLER_PARAMETER = 'tokenPass';

    /**
     * Actions whose help entry is missing on purpose.
     *
     * `search` on the list endpoints takes no parameters at all, so there is nothing to describe;
     * every other action has one.
     *
     * @var array<string, true>
     */
    private const NO_HELP_NEEDED = [
        'AuthToken/search' => true,
        'Client/search' => true,
        'CustomField/search' => true,
        'Eventlog/search' => true,
        'Notification/search' => true,
        'Profile/search' => true,
        'PublicLink/search' => true,
        'Tag/search' => true,
        'User/search' => true,
        'UserGroup/search' => true,
    ];

    /**
     * @param array<string, bool> $read name => required, as the controller asks for it
     * @param array<string, bool> $documented name => required, as the help states it
     */
    #[Test]
    #[DataProvider('apiActions')]
    public function whatAnActionDocumentsIsWhatItReads(string $action, array $read, array $documented): void
    {
        unset($documented[self::NOT_A_CONTROLLER_PARAMETER]);

        self::assertSame(
            [],
            array_keys(array_diff_key($read, $documented)),
            sprintf('%s reads parameters its help does not describe', $action)
        );

        self::assertSame(
            [],
            array_keys(array_diff_key($documented, $read)),
            sprintf('%s documents parameters it never reads', $action)
        );

        foreach ($read as $name => $required) {
            self::assertSame(
                $required,
                $documented[$name],
                sprintf('%s: "%s" is %s', $action, $name, $required ? 'required' : 'optional')
            );
        }
    }

    /**
     * Every API controller group must say what it takes. A group with no help class answers a bad
     * call with an empty hint, which is the one moment the caller had to find out what went wrong.
     */
    #[Test]
    #[DataProvider('apiControllerGroups')]
    public function everyGroupOfEndpointsDeclaresAHelpClass(string $group, ?string $helpClass): void
    {
        self::assertNotNull($helpClass, sprintf('%s sets no help class', $group));
        self::assertTrue(
            is_subclass_of(
                'SP\\Infrastructure\\Adapter\\In\\Api\\Controllers\\Help\\' . $helpClass,
                HelpInterface::class
            ),
            sprintf('%s: %s is not a help class', $group, $helpClass)
        );
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function apiControllerGroups(): array
    {
        $groups = [];

        foreach (self::groupDirectories() as $group => $directory) {
            $helpClass = null;

            foreach (glob($directory . '/*.php') ?: [] as $file) {
                if (preg_match('/setHelpClass\((\w+)::class\)/', (string)file_get_contents($file), $matches)) {
                    $helpClass = $matches[1];
                }
            }

            $groups[$group] = [$group, $helpClass];
        }

        return $groups;
    }

    /**
     * One case per action that both reads parameters and has a help entry.
     *
     * @return array<string, array{string, array<string, bool>, array<string, bool>}>
     */
    public static function apiActions(): array
    {
        $cases = [];

        foreach (self::groupDirectories() as $group => $directory) {
            $help = self::documentedParameters(self::helpClassOf($directory));

            foreach (glob($directory . '/*Controller.php') ?: [] as $file) {
                $action = lcfirst(basename($file, 'Controller.php'));
                $read = self::parametersRead($file);
                $name = $group . '/' . $action;

                if (isset(self::NO_HELP_NEEDED[$name]) && $read === []) {
                    continue;
                }

                $cases[$name] = [$name, $read, $help[$action] ?? []];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, string> group name => directory
     */
    private static function groupDirectories(): array
    {
        $groups = [];

        foreach (glob(self::CONTROLLERS . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $group = basename($directory);

            if ($group !== 'Help') {
                $groups[$group] = $directory;
            }
        }

        return $groups;
    }

    private static function helpClassOf(string $directory): ?string
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (preg_match('/setHelpClass\((\w+)::class\)/', (string)file_get_contents($file), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * What a controller asks the request for. A name asked for more than once counts as required if
     * any of those asks is, which is how the request behaves.
     *
     * @return array<string, bool> name => required
     */
    private static function parametersRead(string $file): array
    {
        preg_match_all(
            '/getParam\w*\(\s*\'([^\']+)\'\s*(?:,\s*(true|false))?/',
            (string)file_get_contents($file),
            $matches,
            PREG_SET_ORDER
        );

        $read = [];

        foreach ($matches as $match) {
            $read[$match[1]] = ($read[$match[1]] ?? false) || (($match[2] ?? '') === 'true');
        }

        ksort($read);

        return $read;
    }

    /**
     * What a help class states, per action.
     *
     * @return array<string, array<string, bool>> action => (name => required)
     */
    private static function documentedParameters(?string $helpClass): array
    {
        if ($helpClass === null) {
            return [];
        }

        $source = (string)file_get_contents(self::CONTROLLERS . '/Help/' . $helpClass . '.php');
        $documented = [];

        preg_match_all(
            '/public static function (\w+)\(\)[^{]*\{(.*?)\n    \}/s',
            $source,
            $methods,
            PREG_SET_ORDER
        );

        foreach ($methods as $method) {
            preg_match_all(
                '/getItem\(\s*\'([^\']+)\'\s*,\s*__\((?:[^()]|\([^()]*\))*\)\s*(?:,\s*(true|false))?\s*\)/',
                $method[2],
                $items,
                PREG_SET_ORDER
            );

            $parameters = [];

            foreach ($items as $item) {
                $parameters[$item[1]] = ($item[2] ?? '') === 'true';
            }

            ksort($parameters);

            $documented[$method[1]] = $parameters;
        }

        return $documented;
    }
}
