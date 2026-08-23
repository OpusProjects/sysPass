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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Notification\Ports\NotificationService;
use SP\Application\User\Ports\UserService;
use RuntimeException;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\EditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\SaveEditController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * The integration harness binds an ACL that answers `true` to everything, so a request dispatched
 * through it cannot be refused. These mock it closed and assert both halves: the refusal reaches
 * the caller, and the service behind the controller is never asked for anything.
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
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'notification', 'create'),
            $notificationService,
            $this->createStub(UserService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingIsRefusedWhenTheAclDenies(): void
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('getById');
        $notificationService->expects(self::never())->method('update');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'notification', 'edit'),
            $notificationService,
            $this->createStub(UserService::class)
        ))->editAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAclDenies(): void
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('update');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'notification', 'saveEdit'),
            $notificationService
        ))->saveEditAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Every one of these actions wraps its body in a `catch` that logs the exception, announces it
     * and answers with its message. Nothing exercised that arm: the integration harness dispatches
     * requests that succeed, so the failure path of the whole web layer went untested. Here the
     * service throws, and the assertion is that the caller is told rather than left with a blank
     * page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function editingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $notificationService = $this->createStub(NotificationService::class);
        $notificationService->method('getById')
                            ->willThrowException(new RuntimeException('the notification could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'notification', 'edit'),
            $notificationService,
            $this->createStub(UserService::class)
        ))->editAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the notification could not be read', $response->subject);
    }
}
