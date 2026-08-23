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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\AccountHistoryManager;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Application;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\AccountHistoryManager\DeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AccountHistoryManager\RestoreController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AccountHistoryManager\SearchController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AccountHistoryGrid;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so a
 * request dispatched through it cannot be denied and the refusal branch of every action goes
 * unexercised. This mocks the ACL closed and asserts both halves: the refusal reaches the caller,
 * and the service behind the controller is never asked to do the thing that was refused.
 *
 * `SearchController` does not carry the guard itself — it inherits `searchAction()` from
 * `SearchGridControllerBase` — but it is checked the same way when the action is dispatched, so
 * it is covered here alongside the other two.
 *
 * None of the three wraps its work in a `try`/`catch`: the guard is the only thing standing
 * between the request and the service, and an exception from a collaborator would simply
 * propagate rather than come back as an `ActionResponse::error()`. So there is no catch-arm test
 * to add for any of them, unlike controllers built on the older per-family pattern.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $accountHistoryService = $this->createMock(AccountHistoryService::class);
        $accountHistoryService->expects(self::never())->method('getById');
        $accountHistoryService->expects(self::never())->method('delete');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'accountHistoryManager', 'delete'),
            $accountHistoryService
        ))->deleteAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function restoringIsRefusedWhenTheAclDenies(): void
    {
        $accountHistoryService = $this->createMock(AccountHistoryService::class);
        $accountHistoryService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new RestoreController(
            $application,
            $this->webControllerHelper($acl, $application, 'accountHistoryManager', 'restore'),
            $accountHistoryService,
            $this->createStub(AccountService::class)
        ))->restoreAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function searchingIsRefusedWhenTheAclDenies(): void
    {
        $accountHistoryService = $this->createMock(AccountHistoryService::class);
        $accountHistoryService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SearchController(
            $application,
            $this->webControllerHelper($acl, $application, 'accountHistoryManager', 'search'),
            $accountHistoryService,
            $this->accountHistoryGrid($application, $acl)
        ))->searchAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    private function accountHistoryGrid(Application $application, AclInterface $acl): AccountHistoryGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new AccountHistoryGrid(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class),
            $acl,
            $theme
        );
    }
}
