<?php
/**
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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\Help;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\AccountHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\CategoryHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\ClientHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\ConfigHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\EventlogHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\TagHelp;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\UserGroupHelp;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class HelpTest
 *
 * The help classes are what an API caller is told when a request is refused for missing
 * parameters, so they are the documentation the client actually receives. None had tests.
 */
#[Group('unitary')]
class HelpTest extends UnitaryTestCase
{
    /**
     * Each documented action describes at least one parameter, and every entry carries the two
     * fields the caller is shown: what it is, and whether it must be supplied.
     */
    #[DataProvider('helpProvider')]
    public function testAnActionDescribesItsParameters(string $helpClass, string $action): void
    {
        $help = $helpClass::getHelpFor($action);

        self::assertArrayHasKey('help', $help);
        self::assertNotEmpty($help['help']);

        foreach ($help['help'] as $entry) {
            foreach ($entry as $name => $spec) {
                self::assertNotSame('', $name);
                self::assertArrayHasKey('description', $spec);
                self::assertNotSame('', $spec['description'], "$name is listed without saying what it is");
                self::assertArrayHasKey('required', $spec);
                self::assertIsBool($spec['required']);
            }
        }
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helpProvider(): array
    {
        $map = [
            AccountHelp::class => ['view', 'create', 'edit', 'search', 'delete'],
            CategoryHelp::class => ['view', 'create', 'edit', 'search', 'delete'],
            ClientHelp::class => ['view', 'create', 'edit', 'search', 'delete'],
            TagHelp::class => ['view', 'create', 'edit', 'search', 'delete'],
            UserGroupHelp::class => ['view', 'create', 'edit', 'search', 'delete'],
        ];

        $cases = [];

        foreach ($map as $class => $actions) {
            $short = substr(strrchr($class, '\\') ?: $class, 1);

            foreach ($actions as $action) {
                $cases["$short::$action"] = [$class, $action];
            }
        }

        return $cases;
    }

    /**
     * The action arrives as the route the request was dispatched to, so the group prefix is
     * stripped before the method is looked up.
     */
    public function testAPrefixedActionResolvesToTheSameHelp(): void
    {
        self::assertSame(TagHelp::getHelpFor('view'), TagHelp::getHelpFor('tag/view'));
    }

    /**
     * An action with nothing documented answers with nothing rather than raising, so a refusal
     * for an undocumented endpoint still produces a response.
     */
    public function testAnUndocumentedActionYieldsNoHelp(): void
    {
        self::assertSame([], TagHelp::getHelpFor('somethingElse'));
        self::assertSame([], TagHelp::getHelpFor('tag/somethingElse'));
    }

    /**
     * Required and optional parameters are distinguished — a caller cannot tell what to send if
     * everything claims to be required.
     */
    public function testRequiredAndOptionalParametersAreDistinguished(): void
    {
        $create = AccountHelp::getHelpFor('create')['help'];

        $required = [];

        foreach ($create as $entry) {
            foreach ($entry as $spec) {
                $required[] = $spec['required'];
            }
        }

        self::assertContains(true, $required, 'an account cannot be created with nothing');
        self::assertContains(false, $required, 'not every field can be mandatory');
    }

    /**
     * The config help documents its own actions rather than the item ones.
     */
    public function testTheConfigHelpDocumentsItsOwnActions(): void
    {
        $help = ConfigHelp::getHelpFor('backup');

        self::assertArrayHasKey('help', $help);
    }

    /**
     * Clearing the event log takes no parameters, and the empty list is a documented fact rather
     * than a missing method: an action nobody wrote a method for answers with nothing at all (see
     * testAnUndocumentedActionYieldsNoHelp()), while `clear()` exists and deliberately says there
     * is nothing to pass.
     */
    public function testTheEventlogClearActionDocumentsNoParameters(): void
    {
        self::assertSame(['help' => []], EventlogHelp::getHelpFor('clear'));
    }
}
