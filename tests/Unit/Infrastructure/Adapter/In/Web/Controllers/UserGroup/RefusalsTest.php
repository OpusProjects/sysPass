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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\UserGroup;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup\ViewController;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so the
 * refusal branch of every action goes unexercised there. These mock it closed and assert both that
 * the refusal reaches the caller and that the service behind the controller is never asked for
 * anything.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(UserGroupService::class);
        $service->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'create'),
            $service,
            $this->createStub(UserService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(UserGroupService::class);
        $service->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'view'),
            $service,
            $this->createStub(UserService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * A new group has no id to read back, so `setViewData()` never calls `UserGroupService`
     * before it builds the `users` select list from `UserService::getAll()` — that is the first
     * collaborator this action reaches, and where the failure the caller must be told about
     * comes from.
     *
     * @throws Exception
     */
    #[Test]
    public function creatingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $userService = $this->createStub(UserService::class);
        $userService->method('getAll')
                     ->willThrowException(new RuntimeException('the users could not be listed'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'create'),
            $this->createStub(UserGroupService::class),
            $userService,
            $this->createStub(CustomFieldDataService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the users could not be listed', $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * `setViewData()` reads the group before it assigns anything else, so a lookup failure there
     * is what the caller must be told about rather than a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function viewingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $service = $this->createStub(UserGroupService::class);
        $service->method('getById')
                ->willThrowException(new RuntimeException('the group could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'view'),
            $service,
            $this->createStub(UserService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the group could not be read', $response->subject);
    }
}
