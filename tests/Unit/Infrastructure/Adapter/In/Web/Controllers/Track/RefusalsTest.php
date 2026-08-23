<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Track;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Application\Security\Ports\TrackService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Track\ClearController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Track\UnlockController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so a
 * request dispatched through it cannot be denied and the refusal branch of every action goes
 * unexercised. These mock the ACL closed and assert both halves: the refusal reaches the caller,
 * and the service behind the controller is never asked to do the thing that was refused.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{

    /**
     * @throws Exception
     */
    #[Test]
    public function clearingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(TrackService::class);
        $service->expects(self::never())->method('clear');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ClearController(
            $application,
            $this->webControllerHelper($acl, $application, 'track', 'clear'),
            $service
        ))->clearAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function unlockingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(TrackService::class);
        $service->expects(self::never())->method('unlock');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new UnlockController(
            $application,
            $this->webControllerHelper($acl, $application, 'track', 'unlock'),
            $service
        ))->unlockAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
