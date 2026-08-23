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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Security\Ports\EventlogService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Eventlog\ClearController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Eventlog\IndexController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Eventlog\SearchController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\EventlogGrid;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * The integration harness binds an ACL that answers `true` to everything, so a request dispatched
 * through it cannot be refused. These mock it closed and assert both halves: the refusal reaches
 * the caller, and the service behind the controller is never asked for anything.
 *
 * `ClearController` matches the usual shape (a `try`/`catch` around an `ActionResponse::error(...)`
 * refusal), so it gets both a refusal test and a catch-arm test. `IndexController` and
 * `SearchController` check access too, but neither wraps its body in a `try`/`catch` — an exception
 * from either's collaborators would propagate out rather than come back as an `ActionResponse`, so
 * there is no catch-arm test for either. `IndexController` also answers a refusal differently from
 * every other controller here: it returns `ActionResponse::ok('')` rather than an error, so a denied
 * visitor sees an empty page instead of a "not allowed" message.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * A real (final, so undoubled) grid — neither action reaches it once the ACL check has already
     * returned, but each controller's constructor still requires a concrete instance.
     *
     * @throws Exception
     */
    private function eventlogGrid(Application $application): EventlogGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new EventlogGrid(
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
    public function clearingIsRefusedWhenTheAclDenies(): void
    {
        $eventlogService = $this->createMock(EventlogService::class);
        $eventlogService->expects(self::never())->method('clear');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ClearController(
            $application,
            $this->webControllerHelper($acl, $application, 'eventlog', 'clear'),
            $eventlogService
        ))->clearAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Nothing in the mocked integration harness ever fails to clear the log, so this catch arm went
     * untested. Here the service throws, and the assertion is that the caller is told rather than
     * left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function clearingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $eventlogService = $this->createStub(EventlogService::class);
        $eventlogService->method('clear')
                        ->willThrowException(new RuntimeException('the event log could not be cleared'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ClearController(
            $application,
            $this->webControllerHelper($acl, $application, 'eventlog', 'clear'),
            $eventlogService
        ))->clearAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the event log could not be cleared', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingTheIndexIsRefusedWhenTheAclDenies(): void
    {
        $eventlogService = $this->createMock(EventlogService::class);
        $eventlogService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new IndexController(
            $application,
            $this->webControllerHelper($acl, $application, 'eventlog', 'index'),
            $eventlogService,
            $this->eventlogGrid($application)
        ))->indexAction();

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function searchingIsRefusedWhenTheAclDenies(): void
    {
        $eventlogService = $this->createMock(EventlogService::class);
        $eventlogService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SearchController(
            $application,
            $this->webControllerHelper($acl, $application, 'eventlog', 'search'),
            $eventlogService,
            $this->eventlogGrid($application)
        ))->searchAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
