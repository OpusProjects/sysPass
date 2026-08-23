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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Track;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
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

    /**
     * What the action does when the work behind it fails.
     *
     * Clearing tracks delegates straight to the service; when that throws, the caller must be told
     * rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function clearingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $service = $this->createStub(TrackService::class);
        $service->method('clear')
                ->willThrowException(new RuntimeException('the tracks could not be cleared'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ClearController(
            $application,
            $this->webControllerHelper($acl, $application, 'track', 'clear'),
            $service
        ))->clearAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the tracks could not be cleared', $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Unlocking a track delegates straight to the service; when that throws, the caller must be
     * told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function unlockingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $service = $this->createStub(TrackService::class);
        $service->method('unlock')
                ->willThrowException(new RuntimeException('the track could not be unlocked'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new UnlockController(
            $application,
            $this->webControllerHelper($acl, $application, 'track', 'unlock'),
            $service
        ))->unlockAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the track could not be unlocked', $response->subject);
    }
}
