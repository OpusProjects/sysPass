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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\NotificationGrid;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridInterface;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The notifications listing is the one grid whose actions depend on who is looking at it, and the
 * difference is not cosmetic: an application administrator manages the notifications everybody
 * sees, while a plain user may only act on their own.
 *
 * The shared grid test builds every grid as a plain user, so the administrator's half of this one
 * was never built at all. Both halves are asserted here, since each is only meaningful against the
 * other.
 */
#[Group('integration')]
class NotificationGridTest extends IntegrationTestCase
{
    private bool $isAdminApp = false;

    /**
     * Creating a notification is an administrator's action — a user does not raise one for
     * themselves — so the button is not offered to anybody else.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPlainUserIsOfferedNeitherCreateNorEdit()
    {
        $grid = $this->whenBuildingTheGrid();

        self::assertNull($this->findAction($grid, AclActionsInterface::NOTIFICATION_CREATE));
        self::assertNull($this->findAction($grid, AclActionsInterface::NOTIFICATION_EDIT));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anApplicationAdministratorIsOfferedBoth()
    {
        $this->isAdminApp = true;

        $grid = $this->whenBuildingTheGrid();

        self::assertNotNull($this->findAction($grid, AclActionsInterface::NOTIFICATION_CREATE));
        self::assertNotNull($this->findAction($grid, AclActionsInterface::NOTIFICATION_EDIT));
    }

    /**
     * What a plain user is left with is narrowed to the sticky rows — the notifications that were
     * raised for them. Losing that filter would put a check and a delete button on every
     * notification in the listing, including the ones that are not theirs to act on.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aPlainUsersActionsAreNarrowedToTheirOwnRows()
    {
        $grid = $this->whenBuildingTheGrid();

        foreach ([AclActionsInterface::NOTIFICATION_CHECK, AclActionsInterface::NOTIFICATION_DELETE] as $actionId) {
            $action = $this->findAction($grid, $actionId);

            self::assertNotNull($action, 'a user can still act on their own notifications');
            self::assertContains('sticky', $this->filterFieldsOf($action));
        }
    }

    /**
     * An administrator's are not narrowed, so they act on the whole listing. What stays is the
     * filter the action carries in its own right: a notification is only checked out or deleted
     * from the rows where that makes sense, for either of them.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anAdministratorsActionsAreNotNarrowed()
    {
        $this->isAdminApp = true;

        $grid = $this->whenBuildingTheGrid();

        foreach ([AclActionsInterface::NOTIFICATION_CHECK, AclActionsInterface::NOTIFICATION_DELETE] as $actionId) {
            $fields = $this->filterFieldsOf($this->findAction($grid, $actionId));

            self::assertNotContains('sticky', $fields);
            self::assertContains('checked', $fields, 'the action keeps the filter it carries anyway');
        }
    }

    /**
     * The bulk delete lives in the listing's own menu rather than on a row, and is narrowed the
     * same way for a plain user.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theBulkDeleteIsInTheListingMenu()
    {
        $grid = $this->whenBuildingTheGrid();

        $menu = $grid->getDataActionsMenu();

        self::assertNotEmpty($menu);
        self::assertTrue($menu[0]->isSelection(), 'a bulk action works on the rows that were ticked');
        self::assertContains('sticky', $this->filterFieldsOf($menu[0]));
    }

    /**
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['isAdminApp' => $this->isAdminApp]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenBuildingTheGrid(): DataGridInterface
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/index'])
        );

        /** @var NotificationGrid $builder */
        $builder = $container->get(NotificationGrid::class);

        return $builder->getGrid(QueryResult::withTotalNumRows([], 0));
    }

    /**
     * An action carries the ACL action it stands for as its id — as a string, since that is what
     * the template prints.
     */
    private function findAction(DataGridInterface $grid, int $actionId): ?DataGridActionInterface
    {
        foreach ($grid->getDataActions() as $action) {
            if ((int)$action->getId() === $actionId) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The row properties an action is filtered on, if any.
     *
     * @return string[]
     */
    private function filterFieldsOf(?DataGridActionInterface $action): array
    {
        return array_column($action?->getFilterRowSource() ?? [], 'field');
    }
}
