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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\AuthToken;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Auth\Ports\AuthTokenActionService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\DeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\EditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\SaveCreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\SaveEditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\SearchController;
use SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken\ViewController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AuthTokenGrid;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * The integration harness binds an ACL that answers `true` to everything, so a request dispatched
 * through it cannot be refused. These mock it closed and assert both halves: the refusal reaches
 * the caller, and the service behind the controller is never asked for anything.
 *
 * None of this family's actions wrap their body in a `try`/`catch` — unlike Notification or
 * CustomField, an exception thrown by a collaborator here would propagate out of the action rather
 * than come back as an `ActionResponse::error(...)`. So there is no catch-arm test to add for any
 * of these controllers: there is no catch arm.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * A real (final, so undoubled) grid — the search action never reaches it once the ACL check
     * has already returned, but the controller's constructor still requires a concrete instance.
     *
     * @throws Exception
     */
    private function authTokenGrid(Application $application): AuthTokenGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new AuthTokenGrid(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class),
            $this->createStub(AclInterface::class),
            $theme
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldService = $this->createMock(CustomFieldDataService::class);
        $customFieldService->expects(self::never())->method('getBy');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'create'),
            $customFieldService,
            $this->createStub(UserService::class),
            $this->createStub(AuthTokenService::class),
            $this->createStub(AuthTokenActionService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('delete');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'delete'),
            $authTokenService,
            $this->createStub(CustomFieldDataService::class)
        ))->deleteAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'edit'),
            $this->createStub(CustomFieldDataService::class),
            $this->createStub(UserService::class),
            $authTokenService,
            $this->createStub(AuthTokenActionService::class)
        ))->editAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingACreateIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'saveCreate'),
            $authTokenService,
            $this->createStub(CustomFieldDataService::class)
        ))->saveCreateAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('update');
        $authTokenService->expects(self::never())->method('refreshAndUpdate');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'saveEdit'),
            $authTokenService,
            $this->createStub(CustomFieldDataService::class)
        ))->saveEditAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'view'),
            $this->createStub(CustomFieldDataService::class),
            $this->createStub(UserService::class),
            $authTokenService,
            $this->createStub(AuthTokenActionService::class)
        ))->viewAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function searchingIsRefusedWhenTheAclDenies(): void
    {
        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SearchController(
            $application,
            $this->webControllerHelper($acl, $application, 'authToken', 'search'),
            $authTokenService,
            $this->authTokenGrid($application)
        ))->searchAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
