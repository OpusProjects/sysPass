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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Notification\Ports\MailService;
use SP\Application\Security\Ports\TrackService;
use SP\Application\User\Ports\UserPassRecoverService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserPassReset\SaveRequestController;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserPassReset\SaveResetController;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What these actions refuse, and what they do when the work behind them fails.
 *
 * This family has no ACL guard at all — `IndexController`, `ResetController`, `SaveRequestController`
 * and `SaveResetController` never call `checkUserAccess()`, deliberately: the whole point of a
 * password-recovery flow is that it has to work for someone who is not signed in, so there is
 * nothing here for `aclThatRefuses()`/`aclThatAllows()` to affect.
 *
 * What both save actions do have is `UserPassResetSaveBase::checkTracking()`, which is the guard
 * that actually applies here — a rate limiter, not an authorization check — and both wrap their
 * whole body in a `catch` that reports a failure rather than letting it escape. The existing
 * integration test (`SaveRequestRefusalsTest`) already covers the business-logic refusals thrown
 * from inside that same `catch` (wrong email, a disabled account, an LDAP account); it does not
 * exercise `checkTracking()`, which is the refusal these tests add, or the `catch` arm itself,
 * which nothing exercises anywhere else.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function savingARequestIsRefusedWhenAttemptsAreExceeded(): void
    {
        $trackService = $this->createMock(TrackService::class);
        $trackService->method('buildTrackRequest')->willReturn($this->trackRequestFor('saveRequest'));
        $trackService->method('checkTracking')->willReturn(true);
        $trackService->expects(self::once())->method('add');

        $userService = $this->createMock(UserService::class);
        $userService->expects(self::never())->method('getByLogin');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new SaveRequestController(
            $application,
            $this->webControllerHelper($acl, $application, 'userPassReset', 'saveRequest'),
            $this->createStub(UserPassRecoverService::class),
            $userService,
            $this->createStub(MailService::class),
            $trackService
        ))->saveRequestAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('Attempts exceeded', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAResetIsRefusedWhenAttemptsAreExceeded(): void
    {
        $trackService = $this->createMock(TrackService::class);
        $trackService->method('buildTrackRequest')->willReturn($this->trackRequestFor('saveReset'));
        $trackService->method('checkTracking')->willReturn(true);
        $trackService->expects(self::once())->method('add');

        $userPassRecoverService = $this->createMock(UserPassRecoverService::class);
        $userPassRecoverService->expects(self::never())->method('getUserIdForHash');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new SaveResetController(
            $application,
            $this->webControllerHelper($acl, $application, 'userPassReset', 'saveReset'),
            $userPassRecoverService,
            $this->createStub(UserService::class),
            $this->createStub(MailService::class),
            $trackService
        ))->saveResetAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('Attempts exceeded', $response->subject);
    }

    /**
     * What the action does when the work behind it fails, once tracking allows it through.
     *
     * It answers exactly what it answers when the request succeeds. This endpoint is reachable
     * without a session, so anything it distinguishes it distinguishes for anybody — a failure
     * reported as itself told an unauthenticated caller whether the login existed. The failure is
     * still recorded and still counted against the caller; only the answer is the same.
     *
     * @throws Exception
     */
    #[Test]
    public function savingARequestReportsAFailureBehindItTheSameWayAsASuccess(): void
    {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn('someone');
        $request->method('analyzeEmail')->willReturn('someone@example.invalid');
        $request->method('analyzeArray')->willReturn(null);
        $request->method('analyzeInt')->willReturn(null);

        $trackService = $this->createStub(TrackService::class);
        $trackService->method('buildTrackRequest')->willReturn($this->trackRequestFor('saveRequest'));
        $trackService->method('checkTracking')->willReturn(false);

        $userService = $this->createStub(UserService::class);
        $userService->method('getByLogin')
                    ->willThrowException(new RuntimeException('the user could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new SaveRequestController(
            $application,
            $this->userPassResetWebControllerHelper($acl, $application, 'saveRequest', $request),
            $this->createStub(UserPassRecoverService::class),
            $userService,
            $this->createStub(MailService::class),
            $trackService
        ))->saveRequestAction();

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('Request sent', $response->subject);
    }

    /**
     * What the action does when the work behind it fails, once tracking allows it through and the
     * two passwords the caller supplied match.
     *
     * @throws Exception
     */
    #[Test]
    public function savingAResetReportsAFailureBehindItRatherThanEscaping(): void
    {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeEncrypted')->willReturn('the-new-password');
        $request->method('analyzeString')->willReturn('the-reset-hash');
        $request->method('analyzeArray')->willReturn(null);
        $request->method('analyzeInt')->willReturn(null);

        $trackService = $this->createStub(TrackService::class);
        $trackService->method('buildTrackRequest')->willReturn($this->trackRequestFor('saveReset'));
        $trackService->method('checkTracking')->willReturn(false);

        $userPassRecoverService = $this->createStub(UserPassRecoverService::class);
        $userPassRecoverService->method('getUserIdForHash')
                               ->willThrowException(new RuntimeException('the reset token could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new SaveResetController(
            $application,
            $this->userPassResetWebControllerHelper($acl, $application, 'saveReset', $request),
            $userPassRecoverService,
            $this->createStub(UserService::class),
            $this->createStub(MailService::class),
            $trackService
        ))->saveResetAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the reset token could not be read', $response->subject);
    }

    /**
     * A real one: `buildTrackRequest()` is called unconditionally from the base controller's
     * constructor, and `TrackRequest` is `final` with a constructor that resolves the address it is
     * given into binary — building one directly is simpler than doubling it.
     *
     * @throws Exception
     */
    private function trackRequestFor(string $source): TrackRequest
    {
        return new TrackRequest(time(), $source, '127.0.0.1');
    }

    /**
     * Mirrors `WebControllerTestCase::webControllerHelper()`, but takes an already-configured
     * `RequestService` rather than building its own — the shared one only configures
     * `analyzeString()`/`analyzeArray()`/`analyzeInt()`, and these actions also read
     * `analyzeEmail()`/`analyzeEncrypted()`, which have to return real values to get past
     * validation and reach the collaborator that is meant to fail.
     *
     * @throws Exception
     */
    private function userPassResetWebControllerHelper(
        AclInterface   $acl,
        Application    $application,
        string         $action,
        RequestService $request
    ): WebControllerHelper {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        $template = $this->createStub(TemplateInterface::class);

        $layoutHelper = new LayoutHelper(
            $application,
            $template,
            $request,
            $theme,
            $this->createStub(CryptPKIHandler::class),
            $uriContext,
            $acl
        );

        $simpleControllerHelper = new SimpleControllerHelper(
            $theme,
            new Router(new Request(), $this->createStub(ResponseService::class)),
            $acl,
            $request,
            new PhpExtensionChecker(),
            $uriContext,
            new RouteContextData('userPassReset', $action, $action . 'Action', [])
        );

        return new WebControllerHelper(
            $simpleControllerHelper,
            $template,
            $this->createStub(BrowserAuthService::class),
            $layoutHelper
        );
    }
}
