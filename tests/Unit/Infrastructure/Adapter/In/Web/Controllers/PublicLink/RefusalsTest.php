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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\PublicLinkService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\SaveCreateFromAccountController;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\SaveEditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\ViewController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * A public link is the one way an account is shown to somebody who is not signed in, so being able
 * to make one is worth refusing properly — and the refusal is unreachable through the integration
 * harness, whose ACL answers `true` to everything.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function creatingALinkIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'create'),
            $publicLinkService,
            $this->createStub(AccountService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Creating a link's view loads every account the caller can pick from before it renders;
     * when that read throws, the caller must be told rather than left with a blank page or an
     * escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function creatingALinkReportsAFailureBehindItRatherThanEscaping(): void
    {
        $accountService = $this->createStub(AccountService::class);
        $accountService->method('getForUser')
                        ->willThrowException(new RuntimeException('the accounts could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'create'),
            $this->createStub(PublicLinkService::class),
            $accountService
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the accounts could not be read', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingALinkIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'view'),
            $publicLinkService,
            $this->createStub(AccountService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Viewing a link reads it by id before the template is rendered; when that read throws, the
     * caller must be told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function viewingALinkReportsAFailureBehindItRatherThanEscaping(): void
    {
        $publicLinkService = $this->createStub(PublicLinkService::class);
        $publicLinkService->method('getById')
                           ->willThrowException(new RuntimeException('the link could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'view'),
            $publicLinkService,
            $this->createStub(AccountService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the link could not be read', $response->subject);
    }

    /**
     * Making a link straight from an account is a second door onto the same thing, so it is
     * refused separately rather than assumed to follow from the first.
     *
     * @throws Exception
     */
    #[Test]
    public function creatingALinkFromAnAccountIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateFromAccountController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'saveCreateFromAccount'),
            $publicLinkService
        ))->saveCreateFromAccountAction(1, 0);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * What the action does when the work behind it fails.
     *
     * Making a link from an account creates it before notifying; when the creation throws, the
     * caller must be told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function creatingALinkFromAnAccountReportsAFailureBehindItRatherThanEscaping(): void
    {
        $publicLinkService = $this->createStub(PublicLinkService::class);
        $publicLinkService->method('create')
                           ->willThrowException(new RuntimeException('the link could not be created'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new SaveCreateFromAccountController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'saveCreateFromAccount'),
            $publicLinkService
        ))->saveCreateFromAccountAction(1, 0);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the link could not be created', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('refresh');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'saveEdit'),
            $publicLinkService
        ))->saveEditAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    // No catch-arm test for saveEditAction(): the form it validates first
    // (PublicLinkSaveBase constructs a real, un-injectable PublicLinkForm) always throws
    // ValidationException before any collaborator this test could double is reached — the
    // request stub this harness builds answers null to analyzeInt('accountId'), so
    // PublicLinkForm::checkCommon() throws every time. That lands in the action's earlier
    // `catch (ValidationException $e)` block, which returns directly without calling
    // processException() or notifying an 'exception' event, so it can never exercise the
    // general `catch (Exception $e)` arm this file is testing.
}
