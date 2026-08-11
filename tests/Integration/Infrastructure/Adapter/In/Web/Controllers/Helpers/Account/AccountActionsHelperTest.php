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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsHelper;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the helper that decides which buttons an account view offers. It had no tests.
 *
 * The buttons are derived from the permission the viewer holds for that account, so this is
 * where "can this user see the password" becomes "is there a button for it". A mistake here
 * shows the wrong controls while every endpoint around it still answers correctly.
 */
#[Group('integration')]
class AccountActionsHelperTest extends IntegrationTestCase
{
    private const ACCOUNT_ID = 100;

    /**
     * Every action builder produces a usable button: something to label it, and a route for it
     * to reach.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('actionBuilderProvider')]
    public function anActionBuilderProducesAUsableButton(string $method): void
    {
        $action = $this->helper()->{$method}();

        self::assertNotEmpty($action->getName(), 'a button with no name cannot be understood');
        self::assertNotEmpty($action->getTitle());
        self::assertNotEmpty($action->getIcon()->getIcon(), 'the account view renders these as icons');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actionBuilderProvider(): array
    {
        $methods = [
            'getViewAction',
            'getBackAction',
            'getEditPassAction',
            'getEditAction',
            'getRequestAction',
            'getRestoreAction',
            'getSaveAction',
            'getDeleteAction',
            'getPublicLinkRefreshAction',
            'getPublicLinkDeleteAction',
            'getPublicLinkAction',
            'getViewPassHistoryAction',
            'getCopyPassHistoryAction',
            'getViewPassAction',
            'getCopyPassAction',
            'getCopyAction',
        ];

        return array_combine($methods, array_map(static fn(string $m) => [$m], $methods));
    }

    /**
     * A viewer with no rights over the account is offered nothing that would act on it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anAccountWithNoPermissionsOffersNoActions(): void
    {
        $actions = $this->helper()->getActionsForAccount(
            new AccountPermission(AclActionsInterface::ACCOUNT_VIEW),
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        // Only the back button, which navigates rather than acting on the account.
        self::assertCount(1, $actions);
    }

    /**
     * A grant adds its own button — but only on top of the underlying access. The permission
     * refuses to show a control the viewer could not actually use: setShowEdit() keeps the flag
     * only when the account is editable, so asking for the button without that access silently
     * gets nothing.
     *
     * (Viewing a password is not offered here; that button lives in the overflow menu below.)
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aGrantAddsItsButtonOnlyOnTopOfTheUnderlyingAccess(): void
    {
        $withoutAccess = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);
        $withoutAccess->setShowEdit(true);

        self::assertCount(
            1,
            $this->helper()->getActionsForAccount($withoutAccess, new AccountActionsDto(self::ACCOUNT_ID)),
            'a show flag without the matching access grants no button'
        );

        $withAccess = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);
        $withAccess->setResultEdit(true);
        $withAccess->setShowEdit(true);

        self::assertGreaterThan(
            1,
            count($this->helper()->getActionsForAccount($withAccess, new AccountActionsDto(self::ACCOUNT_ID)))
        );
    }

    /**
     * Viewing a historical entry offers a way back to the current one, which the current view
     * does not need.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function ahistoricalEntryOffersAWayBackToTheCurrentAccount(): void
    {
        $actions = $this->helper()->getActionsForAccount(
            new AccountPermission(AclActionsInterface::ACCOUNT_VIEW, true),
            new AccountActionsDto(self::ACCOUNT_ID, 500)
        );

        $back = $actions[0];

        self::assertSame('View Current', $back->getName());
        self::assertArrayHasKey('item-id', $back->getData());
    }

    /**
     * The grouped actions are the overflow menu, and are likewise permission-derived.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theOverflowMenuFollowsThePermissions(): void
    {
        // Deleting is offered from a listing, not from the account view, so the action the
        // permission was built for decides whether the button is available at all.
        $none = $this->helper()->getActionsGrouppedForAccount(
            new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH),
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH);
        $permission->setResultEdit(true);
        $permission->setShowDelete(true);

        $withDelete = $this->helper()->getActionsGrouppedForAccount(
            $permission,
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        self::assertGreaterThan(count($none), count($withDelete), 'a grant has to add its button');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function helper(): AccountActionsHelper
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        return $container->get(AccountActionsHelper::class);
    }
}
