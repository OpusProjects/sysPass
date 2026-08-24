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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\User;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserPassRecoverService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Application\Notification\Ports\MailService;
use SP\Infrastructure\Adapter\In\Web\Controllers\User\SaveCreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\User\ViewController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * Creating a user is the sharpest of these to leave unexercised: the account it would make can be
 * an administrator. `IntegrationTestCase` binds an ACL that answers `true` to everything, so the
 * refusal branch cannot be reached through it.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function creatingAUserIsRefusedWhenTheAclDenies(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->expects(self::never())->method('create');
        $userService->expects(self::never())->method('createWithMasterPass');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'user', 'saveCreate'),
            $userService,
            $this->createStub(CustomFieldDataService::class),
            $this->createStub(MailService::class),
            $this->createStub(UserPassRecoverService::class),
            $this->createStub(UserProfileService::class)
        ))->saveCreateAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingAUserIsRefusedWhenTheAclDenies(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'user', 'view'),
            $userService,
            $this->createStub(UserGroupService::class),
            $this->createStub(UserProfileService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * `setViewData()` reads the user before it assigns anything else, so a lookup failure there is
     * what the caller must be told about rather than a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function viewingAUserReportsAFailureBehindItRatherThanEscaping(): void
    {
        $userService = $this->createStub(UserService::class);
        $userService->method('getById')
                     ->willThrowException(new RuntimeException('the user could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'user', 'view'),
            $userService,
            $this->createStub(UserGroupService::class),
            $this->createStub(UserProfileService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the user could not be read', $response->subject);
    }
}
