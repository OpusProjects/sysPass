<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Notification\Ports\NotificationService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\EditController;
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
}
