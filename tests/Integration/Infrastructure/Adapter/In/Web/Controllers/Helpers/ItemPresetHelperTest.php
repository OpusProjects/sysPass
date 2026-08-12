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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Helpers;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\ItemPreset\Models\AccountPermission;
use SP\Domain\ItemPreset\Models\AccountPrivate;
use SP\Domain\ItemPreset\Models\ItemPreset;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the helper that assembles a preset's view. Each preset type gets a different form, and
 * only the session-timeout one was reached.
 *
 * The payload lives in a serialized blob on the row, so viewing a preset has to deserialize it
 * and put its values back on the form — a preset that rendered an empty form would look fine
 * until somebody saved it and lost what it held.
 */
#[Group('integration')]
class ItemPresetHelperTest extends IntegrationTestCase
{
    /**
     * A permission preset shows who it grants access to.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPresetForm')]
    public function aPermissionPresetRendersItsForm()
    {
        $this->givenPreset(
            ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION,
            new AccountPermission([1], [2], [3], [4])
        );

        $this->whenViewing();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPresetForm')]
    public function aPrivateAccountPresetRendersItsForm()
    {
        $this->givenPreset(
            ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE,
            new AccountPrivate(true, true)
        );

        $this->whenViewing();
    }

    /**
     * The password rules are numbers and switches, so the form has to come back carrying them
     * rather than the defaults.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPasswordRules')]
    public function aPasswordPresetRendersItsRules()
    {
        $this->givenPreset(
            ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD,
            new Password(24, true, true, false, true, true, false, 3600, 4)
        );

        $this->whenViewing();
    }

    private function givenPreset(string $type, object $payload): void
    {
        $preset = (new ItemPreset(
            [
                'id' => 100,
                'type' => $type,
                'userId' => 1,
                'fixed' => 0,
                'priority' => 10,
            ]
        ))->dehydrate($payload);

        // Init looks up the session-timeout preset on every request. Answering that lookup with
        // this preset makes it hydrate a permission blob as a SessionTimeout and fail, so the
        // two are told apart by what they bind.
        $this->databaseQueryResolver = function (QueryData $queryData) use ($preset): QueryResult {
            if ($queryData->getMapClassName() !== ItemPreset::class) {
                // Everything else the view loads — the user and group pickers — keeps the
                // harness default rather than being handed a preset.
                return new QueryResult([], 1, 100);
            }

            $bound = $queryData->getQuery()->getBindValues();

            if (in_array(ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT, $bound, true)) {
                return new QueryResult([]);
            }

            return new QueryResult([$preset]);
        };
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenViewing(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerPresetForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertStringContainsString('frmItemPreset', $json->data->html);
    }

    /**
     * The stored length has to reach the form: a preset that rendered the default would quietly
     * replace the rule the administrator saved.
     */
    private function outputCheckerPasswordRules(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertStringContainsString('frmItemPreset', $json->data->html);
        self::assertStringContainsString('24', $json->data->html);
    }
}
