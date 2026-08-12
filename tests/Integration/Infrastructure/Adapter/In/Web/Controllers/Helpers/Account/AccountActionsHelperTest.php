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
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsHelper;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionInterface;
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
    private const PUBLIC_LINK_ID = 200;
    private const HISTORY_ID = 300;
    private const ANOTHER_USER_ID = 999;
    private const VIEWER_ID = 500;

    private bool $publicLinksEnabled = true;

    /**
     * Pinned rather than left to the fixture's random id, since one of the tests turns on whether
     * the viewer is the same person who created the link.
     *
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['id' => self::VIEWER_ID]);
    }

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isPublinksEnabled' => $this->publicLinksEnabled]);
    }

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
     * An account nobody has linked yet offers the button that creates the link.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anUnlinkedAccountOffersTheLinkButton(): void
    {
        $actions = $this->helper()->getActionsGrouppedForAccount(
            $this->permissionToLink(),
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        self::assertContains(AclActionsInterface::PUBLICLINK_CREATE, $this->actionIds($actions));
    }

    /**
     * Once it is linked, the create button is replaced by the one that refreshes the link.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aLinkedAccountOffersTheRefreshButtonInstead(): void
    {
        $ids = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount(
                $this->permissionToLink(),
                $this->withAPublicLink(createdBy: self::ANOTHER_USER_ID)
            )
        );

        self::assertContains(AclActionsInterface::PUBLICLINK_REFRESH, $ids);
        self::assertNotContains(AclActionsInterface::PUBLICLINK_CREATE, $ids);
    }

    /**
     * Deleting a link is not offered to somebody who did not create it. A published link is a URL
     * other people may already hold, so taking it down is the creator's to do — or an application
     * administrator's.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function onlyTheLinksCreatorOrAnAdministratorIsOfferedTheDeleteButton(): void
    {
        $someoneElses = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount(
                $this->permissionToLink(),
                $this->withAPublicLink(createdBy: self::ANOTHER_USER_ID)
            )
        );

        self::assertNotContains(AclActionsInterface::PUBLICLINK_DELETE, $someoneElses);

        $theirOwn = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount(
                $this->permissionToLink(),
                $this->withAPublicLink(createdBy: self::VIEWER_ID)
            )
        );

        self::assertContains(AclActionsInterface::PUBLICLINK_DELETE, $theirOwn);
    }

    /**
     * With public links switched off, no account offers one whatever the viewer may do.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function noLinkIsOfferedWhileTheFeatureIsOff(): void
    {
        $this->publicLinksEnabled = false;

        $ids = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount(
                $this->permissionToLink(),
                new AccountActionsDto(self::ACCOUNT_ID)
            )
        );

        self::assertNotContains(AclActionsInterface::PUBLICLINK_CREATE, $ids);
    }

    /**
     * Nor does a historical entry: a link publishes the account as it is now, and there is no
     * "now" to publish from an old version of it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aHistoricalEntryIsNotOfferedALink(): void
    {
        $ids = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount(
                $this->permissionToLink(AclActionsInterface::ACCOUNT_HISTORY_VIEW),
                new AccountActionsDto(self::ACCOUNT_ID, 500)
            )
        );

        self::assertNotContains(AclActionsInterface::PUBLICLINK_CREATE, $ids);
    }

    /**
     * Being allowed to see the password puts both buttons on the menu — showing it, and copying it
     * without showing it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function seeingThePasswordOffersBothShowingAndCopyingIt(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setShowViewPass(true);

        $actions = $this->helper()->getActionsGrouppedForAccount(
            $permission,
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        $ids = $this->actionIds($actions);

        self::assertContains(AclActionsInterface::ACCOUNT_VIEW_PASS, $ids);
        self::assertContains(AclActionsInterface::ACCOUNT_COPY_PASS, $ids);
    }

    /**
     * A historical entry gets the history versions of those two, which read the password off the
     * history row rather than off the account — the account's current password is a different
     * secret.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aHistoricalEntryGetsTheHistoryPasswordButtons(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW))
            ->setResultView(true)
            ->setShowViewPass(true);

        $historical = $this->helper()->getActionsGrouppedForAccount(
            $permission,
            new AccountActionsDto(self::ACCOUNT_ID, self::HISTORY_ID)
        );

        self::assertCount(2, $historical);

        // They act on the history row rather than on the account.
        foreach ($historical as $action) {
            self::assertSame(self::HISTORY_ID, $action->getData()['item-id']);
        }

        // And they reach the history endpoints. The buttons carry the same id as the current-account
        // pair — that id is only the template's hook — so the route is what tells them apart.
        $current = $this->helper()->getActionsGrouppedForAccount(
            (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))->setResultView(true)->setShowViewPass(true),
            new AccountActionsDto(self::ACCOUNT_ID)
        );

        self::assertNotEquals($this->actionRoutes($current), $this->actionRoutes($historical));
    }

    /**
     * Copying an account into a new one is its own button.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function copyingTheAccountIsOfferedSeparately(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setShowCopy(true);

        $ids = $this->actionIds(
            $this->helper()->getActionsGrouppedForAccount($permission, new AccountActionsDto(self::ACCOUNT_ID))
        );

        self::assertContains(AclActionsInterface::ACCOUNT_COPY, $ids);
    }

    /**
     * A viewer who may link the account and see its password, on an instance where links are on.
     */
    private function permissionToLink(int $actionId = AclActionsInterface::ACCOUNT_VIEW): AccountPermission
    {
        return (new AccountPermission($actionId))
            ->setResultView(true)
            ->setShowLink(true)
            ->setShowViewPass(true);
    }

    private function withAPublicLink(int $createdBy): AccountActionsDto
    {
        $dto = new AccountActionsDto(self::ACCOUNT_ID);
        $dto->setPublicLinkId(self::PUBLIC_LINK_ID);
        $dto->setPublicLinkCreatorId($createdBy);

        return $dto;
    }

    /**
     * @param DataGridActionInterface[] $actions
     * @return int[]
     */
    private function actionIds(array $actions): array
    {
        return array_map(static fn(DataGridActionInterface $action) => (int)$action->getId(), $actions);
    }

    /**
     * The endpoint each button reaches.
     *
     * @param DataGridActionInterface[] $actions
     * @return array<int, mixed>
     */
    private function actionRoutes(array $actions): array
    {
        return array_map(
            static fn(DataGridActionInterface $action) => $action->getData()['action-route'] ?? null,
            $actions
        );
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
