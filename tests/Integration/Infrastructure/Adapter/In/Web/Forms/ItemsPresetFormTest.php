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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the preset form, which builds a different payload for each preset type. Only the
 * session-timeout branch was reached by the controller tests; the other three were not.
 *
 * The form is driven through its endpoint rather than constructed directly, so the request
 * parsing is exercised along with the branch it selects.
 */
#[Group('integration')]
class ItemsPresetFormTest extends IntegrationTestCase
{
    /**
     * A permission preset has to name somebody, otherwise it grants nothing and would sit in
     * the list doing nothing.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPermissionPresetWithNobodyNamedIsRefused()
    {
        $this->whenSaving(['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION]);

        $this->expectOutputString(
            '{"status":"ERROR","description":"There aren\'t any defined permissions","data":null}'
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPermissionPresetNamingAUserIsAccepted()
    {
        $this->whenSaving(
            [
                'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION,
                'users_view' => [5, 6],
                'user_groups_edit' => [7],
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Value created","data":null}');
    }

    /**
     * The private-account preset is a pair of switches, so it is accepted with neither set —
     * that is a meaningful preset, not an empty one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPrivateAccountPresetIsAcceptedWithItsSwitchesOff()
    {
        $this->whenSaving(['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE]);

        $this->expectOutputString('{"status":"OK","description":"Value created","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPasswordPresetIsBuiltFromItsRules()
    {
        $this->whenSaving(
            [
                'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD,
                'length' => 16,
                'use_numbers_enabled' => 'on',
                'use_letters_enabled' => 'on',
                'expire_time' => 3600,
                'score' => 3,
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Value created","data":null}');
    }

    /**
     * The rule may carry a regular expression, which is compiled before it is stored — an
     * invalid one would otherwise be saved and fail later, when a password is generated against
     * it rather than when it was entered.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPasswordPresetWithAnInvalidExpressionIsRefused()
    {
        $this->whenSaving(
            [
                'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD,
                'length' => 16,
                'regex' => '([unclosed',
            ]
        );

        $this->expectOutputString(
            '{"status":"ERROR","description":"Invalid regular expression","data":null}'
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenSaving(array $fields): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'itemPreset/saveCreate'],
                array_merge(['user_id' => 1, 'priority' => 10], $fields)
            )
        );

        IntegrationTestCase::runApp($container);
    }
}
