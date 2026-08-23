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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Status;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Status\CheckNoticesController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Status\CheckReleaseController;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What these actions do when the caller may not check status, and when the network call behind
 * them fails.
 *
 * Neither controller here calls `checkUserAccess()` — `StatusBase` extends `SimpleControllerBase`
 * but declares no `initialize()`, so nothing runs at construction time. The guard is
 * `StatusBase::mayCheckStatus()`, called from inside each action: it is deliberately not an ACL
 * check either, but a direct read of `isAdminApp`/`isDemoEnabled`, because `GetEnvironmentController`
 * uses the exact same condition to decide whether to offer the caller these endpoints at all, and
 * both controllers sit in `Init::PARTIAL_INIT`, which skips the session check that would otherwise
 * stop an anonymous request from reaching them.
 *
 * `SimpleControllerBase::$eventDispatcher` is typed as the concrete `EventDispatcher`, not
 * `EventDispatcherInterface`, so `WebControllerTestCase::applicationForASignedInUser()` cannot be
 * used here — it injects a stub of the interface, which is a `TypeError` the moment
 * `SimpleControllerBase`'s constructor assigns it, before either action's guard is ever reached.
 * `application()` below mirrors it with a real `EventDispatcher` instead.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function checkingNoticesIsRefusedWhenTheCallerMayNotCheckStatus(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');

        $response = (new CheckNoticesController(
            $this->application(isAdminApp: false),
            $this->simpleControllerHelper($this->aclThatAllows(), 'status', 'checkNotices'),
            $client
        ))->checkNoticesAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('Notifications not available', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function checkingReleaseIsRefusedWhenTheCallerMayNotCheckStatus(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');

        $response = (new CheckReleaseController(
            $this->application(isAdminApp: false),
            $this->simpleControllerHelper($this->aclThatAllows(), 'status', 'checkRelease'),
            $client
        ))->checkReleaseAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('Version unavailable', $response->subject);
    }

    /**
     * What the action does when the outbound call behind it fails, once the caller is allowed to
     * make it.
     *
     * @throws Exception
     */
    #[Test]
    public function checkingNoticesReportsAFailureBehindItRatherThanEscaping(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('request')->willThrowException(new RuntimeException('the notices server could not be reached'));

        $response = (new CheckNoticesController(
            $this->application(isAdminApp: true),
            $this->simpleControllerHelper($this->aclThatAllows(), 'status', 'checkNotices'),
            $client
        ))->checkNoticesAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the notices server could not be reached', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function checkingReleaseReportsAFailureBehindItRatherThanEscaping(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('request')->willThrowException(new RuntimeException('the release server could not be reached'));

        $response = (new CheckReleaseController(
            $this->application(isAdminApp: true),
            $this->simpleControllerHelper($this->aclThatAllows(), 'status', 'checkRelease'),
            $client
        ))->checkReleaseAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the release server could not be reached', $response->subject);
    }

    /**
     * Mirrors `WebControllerTestCase::applicationForASignedInUser()`, with two differences:
     * `isAdminApp` is a parameter, since `mayCheckStatus()` is what these tests are about, and the
     * event dispatcher is a real `EventDispatcher` rather than a stub of the interface — see the
     * class docblock for why the stub cannot be used against a `SimpleControllerBase` subclass.
     * `isCheckNotices`/`isCheckUpdates` are left `true`: `mayCheckStatus()` short-circuits the
     * refusal tests' `||` before either is read, and the failure tests need them true to reach the
     * network call at all.
     *
     * @throws Exception
     */
    private function application(bool $isAdminApp): Application
    {
        $session = $this->createStub(SessionContext::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getAuthCompleted')->willReturn(true);
        $session->method('getUserData')->willReturn(
            new UserDto(
                id: 7,
                userGroupId: 2,
                login: 'jdoe',
                ssoLogin: 'jdoe@sso.example',
                isAdminApp: $isAdminApp,
                isAdminAcc: false
            )
        );
        $session->method('getUserProfile')->willReturn(new ProfileData());

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isDemoEnabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn('the-password-salt');
        $configData->method('isAuthBasicEnabled')->willReturn(false);
        $configData->method('isCheckNotices')->willReturn(true);
        $configData->method('isCheckUpdates')->willReturn(true);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        return new Application($config, new EventDispatcher(), $session);
    }

    /**
     * `SimpleControllerBase` takes a `SimpleControllerHelper`, not the `WebControllerHelper` the
     * shared harness builds for `ControllerBase` subclasses — this mirrors
     * `WebControllerTestCase::webControllerHelper()`'s construction of that inner object directly.
     *
     * @throws Exception
     */
    private function simpleControllerHelper(
        AclInterface $acl,
        string       $controller,
        string       $action
    ): SimpleControllerHelper {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn(null);
        $request->method('analyzeArray')->willReturn(null);
        $request->method('analyzeInt')->willReturn(null);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        return new SimpleControllerHelper(
            $theme,
            new Router(new Request(), $this->createStub(ResponseService::class)),
            $acl,
            $request,
            new PhpExtensionChecker(),
            $uriContext,
            new RouteContextData($controller, $action, $action . 'Action', [])
        );
    }
}
