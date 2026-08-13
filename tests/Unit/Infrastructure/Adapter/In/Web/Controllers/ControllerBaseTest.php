<?php
/*
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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The base every web controller shares, and the guards on it that decide whether a request is even
 * allowed to reach a controller's own action: is anybody signed in, is that sign-in as complete as
 * the action demands, does the browser's own identity still match the session, and — separately —
 * does the ACL actually grant the action being asked for. What each guard's failure hands back is
 * what matters here, not just that the line executed.
 */
#[Group('unitary')]
class ControllerBaseTest extends UnitaryTestCase
{
    private const SALT = 'the-password-salt';

    /**
     * The web module always binds Context to a session-backed implementation, so this is a wiring
     * guard rather than something a live request can trigger — but if that wiring is ever broken,
     * refusing to build the controller (rather than crashing later on a missing session method) is
     * exactly what should happen, and this is the only way to exercise it.
     *
     * @throws Exception
     */
    #[Test]
    public function aContextThatIsNotSessionBackedRefusesToBuildTheController(): void
    {
        $badContext = $this->createStub(Context::class);
        $badApplication = new Application(
            $this->createStub(ConfigFileService::class),
            $this->createStub(EventDispatcherInterface::class),
            $badContext
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a session-backed context');

        $this->buildController(applicationOverride: $badApplication);
    }

    /**
     * With nobody signed in, the guard refuses the request outright.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aRequestWithNoSignedInUserIsTimedOut(): void
    {
        $controller = $this->buildController(loggedIn: false);

        $this->expectException(SessionTimeout::class);

        $controller->checkLoggedInPublic();
    }

    /**
     * A session that is signed in but has not finished a two-step login is not the same as a fully
     * authenticated one — an action that demands full auth refuses it exactly like no session at
     * all.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aPartiallyAuthenticatedSessionIsTimedOutWhenFullAuthIsRequired(): void
    {
        $controller = $this->buildController(loggedIn: true, authCompleted: false);

        $this->expectException(SessionTimeout::class);

        $controller->checkLoggedInPublic(true);
    }

    /**
     * With web-server (basic/SSO) auth enabled, the session is not trusted on its own — the
     * browser's own authenticated identity has to still match either the account's login or its SSO
     * login. When it matches neither, the request is refused even though the session itself is
     * perfectly valid.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aBrowserIdentityThatNoLongerMatchesTheSessionIsRefused(): void
    {
        $controller = $this->buildController(
            loggedIn: true,
            authCompleted: true,
            authBasicEnabled: true,
            browserAuthConfigurator: static function (BrowserAuthService|Stub $browser): void {
                $browser->method('checkServerAuthUser')->willReturn(false);
            }
        );

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Invalid browser auth');

        $controller->checkLoggedInPublic();
    }

    /**
     * The same check passes once the browser's identity matches either login the account carries —
     * a plain login match is enough, the SSO login is never even consulted.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aMatchingBrowserIdentityPassesTheGuard(): void
    {
        $controller = $this->buildController(
            loggedIn: true,
            authCompleted: true,
            authBasicEnabled: true,
            browserAuthConfigurator: static function (BrowserAuthService|Stub $browser): void {
                $browser->method('checkServerAuthUser')->willReturnCallback(
                    static fn(string $login): bool => $login === 'jdoe'
                );
            }
        );

        $this->expectNotToPerformAssertions();

        $controller->checkLoggedInPublic();
    }

    /**
     * With web-server auth disabled entirely, the browser-identity check is never even reached — a
     * valid session is enough on its own.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function authBasicDisabledSkipsTheBrowserCheckEntirely(): void
    {
        $browser = $this->createMock(BrowserAuthService::class);
        $browser->expects($this->never())->method('checkServerAuthUser');

        $controller = $this->buildController(
            loggedIn: true,
            authCompleted: true,
            authBasicEnabled: false,
            browser: $browser
        );

        $controller->checkLoggedInPublic();
    }

    /**
     * An application administrator bypasses the ACL check entirely — the ACL is never even asked.
     *
     * @throws Exception
     */
    #[Test]
    public function anAppAdminBypassesTheAclCheck(): void
    {
        $acl = $this->createMock(AclInterface::class);
        $acl->expects($this->never())->method('checkUserAccess');

        $controller = $this->buildController(isAdminApp: true, acl: $acl);

        self::assertTrue($controller->checkAccessPublic(123));
    }

    /**
     * A non-admin user is denied exactly when the ACL says so — this is the permission-denial path
     * the integration suite can never exercise, because its ACL double is permanently open.
     *
     * @throws Exception
     */
    #[Test]
    public function aNonAdminIsDeniedWhenTheAclRefuses(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);

        $controller = $this->buildController(isAdminApp: false, acl: $acl);

        self::assertFalse($controller->checkAccessPublic(123));
    }

    /**
     * ...and is allowed exactly when the ACL grants it.
     *
     * @throws Exception
     */
    #[Test]
    public function aNonAdminIsAllowedWhenTheAclGrants(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);

        $controller = $this->buildController(isAdminApp: false, acl: $acl);

        self::assertTrue($controller->checkAccessPublic(123));
    }

    /**
     * A view page with no "from" parameter at all has nothing to carry, so nothing is ever exposed
     * to the template.
     *
     * @throws Exception
     */
    #[Test]
    public function aRequestWithNoFromParamAssignsNothingToTheView(): void
    {
        [$template, $captured] = $this->spyingTemplate();

        $request = $this->buildRequestStub();

        $controller = $this->buildController(template: $template, request: $request);
        $controller->prepareSignedUriOnViewPublic();

        self::assertArrayNotHasKey('from', $captured->assigned);
        self::assertArrayNotHasKey('from_hash', $captured->assigned);
    }

    /**
     * A "from" parameter whose signature checks out is exposed to the template, alongside a freshly
     * computed hash for it — this is what lets the login page link back to where the user was
     * headed.
     *
     * @throws Exception
     */
    #[Test]
    public function aValidSignedFromIsExposedToTheView(): void
    {
        [$template, $captured] = $this->spyingTemplate();

        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => $param === 'from' ? 'account/view/1' : null
        );

        $controller = $this->buildController(template: $template, request: $request);
        $controller->prepareSignedUriOnViewPublic();

        self::assertSame('account/view/1', $captured->assigned['from']);
        self::assertArrayHasKey('from_hash', $captured->assigned);
    }

    /**
     * A "from" parameter whose signature does not check out is dropped rather than trusted — the
     * template never sees it, exactly like the deep-link helper the login controller uses.
     *
     * @throws Exception
     */
    #[Test]
    public function anInvalidSignatureIsNotExposedToTheView(): void
    {
        [$template, $captured] = $this->spyingTemplate();

        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => $param === 'from' ? 'account/view/1' : null
        );
        $request->method('verifySignature')->willThrowException(SPException::error('Invalid signature'));

        $controller = $this->buildController(template: $template, request: $request);
        $controller->prepareSignedUriOnViewPublic();

        self::assertArrayNotHasKey('from', $captured->assigned);
        self::assertArrayNotHasKey('from_hash', $captured->assigned);
    }

    /**
     * A TemplateInterface double that records every assign() call, so a test can inspect exactly
     * what was exposed to the view without depending on a real renderer.
     *
     * @return array{0: TemplateInterface, 1: stdClass}
     * @throws Exception
     */
    private function spyingTemplate(): array
    {
        $captured = new stdClass();
        $captured->assigned = [];

        $template = $this->createStub(TemplateInterface::class);
        $template->method('assign')->willReturnCallback(
            static function (string $name, mixed $value) use ($captured): void {
                $captured->assigned[$name] = $value;
            }
        );

        return [$template, $captured];
    }

    /**
     * @throws Exception
     */
    private function buildRequestStub(): RequestService|Stub
    {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn(null);

        return $request;
    }

    /**
     * Builds a real ControllerBase subclass through its actual constructor — the guards under test
     * live on the object the constructor produces, not on some smaller unit that could be isolated
     * more cheaply. Every collaborator that is never exercised by the guard under test is a stub;
     * the ones that are (session, ACL, browser auth, config, request, template) are configurable per
     * test.
     *
     * @throws Exception
     */
    private function buildController(
        bool                     $loggedIn = true,
        bool                     $authCompleted = true,
        bool                     $authBasicEnabled = false,
        bool                     $isAdminApp = false,
        ?AclInterface            $acl = null,
        ?callable                $browserAuthConfigurator = null,
        ?BrowserAuthService      $browser = null,
        ?TemplateInterface       $template = null,
        ?RequestService          $request = null,
        ?Application             $applicationOverride = null
    ): ControllerBaseFixture {
        $userDto = new UserDto(
            id: 7,
            userGroupId: 2,
            login: 'jdoe',
            ssoLogin: 'jdoe@sso.example',
            isAdminApp: $isAdminApp,
            isAdminAcc: false
        );

        $session = $this->createStub(SessionContext::class);
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getAuthCompleted')->willReturn($authCompleted);
        $session->method('getUserData')->willReturn($userDto);
        $session->method('getUserProfile')->willReturn(new ProfileData());

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isDemoEnabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn(self::SALT);
        $configData->method('isAuthBasicEnabled')->willReturn($authBasicEnabled);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        // LayoutHelper carries its own copy of the same session-backed-context guard
        // (HelperBase). Building it against a known-good, session-backed Application
        // — even when the fixture under test is handed a broken one — keeps that guard
        // out of the way, so only ControllerBase's own check is exercised.
        $layoutHelperApplication = new Application($config, $eventDispatcher, $session);

        $application = $applicationOverride ?? $layoutHelperApplication;

        $request ??= $this->buildRequestStub();

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');

        $acl ??= $this->createStub(AclInterface::class);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        $browser ??= $this->createStub(BrowserAuthService::class);

        if ($browserAuthConfigurator !== null) {
            $browserAuthConfigurator($browser);
        }

        $template ??= $this->createStub(TemplateInterface::class);

        $router = new Router(new Request(), $this->createStub(ResponseService::class));

        $routeContextData = new RouteContextData('index', 'index', 'indexAction', []);

        $extensionChecker = new PhpExtensionChecker();

        $cryptPki = $this->createStub(CryptPKIHandler::class);

        $layoutHelper = new LayoutHelper(
            $layoutHelperApplication,
            $template,
            $request,
            $theme,
            $cryptPki,
            $uriContext,
            $acl
        );

        $simpleControllerHelper = new SimpleControllerHelper(
            $theme,
            $router,
            $acl,
            $request,
            $extensionChecker,
            $uriContext,
            $routeContextData
        );

        $webControllerHelper = new WebControllerHelper($simpleControllerHelper, $template, $browser, $layoutHelper);

        return new ControllerBaseFixture($application, $webControllerHelper);
    }
}

/**
 * ControllerBase declares no abstract methods of its own, so a bare concrete subclass is enough to
 * build one through the real constructor; this exposes the protected guards under test.
 */
final class ControllerBaseFixture extends ControllerBase
{
    public function checkLoggedInPublic(bool $requireAuthCompleted = true): void
    {
        $this->checkLoggedIn($requireAuthCompleted);
    }

    public function checkAccessPublic(int $action): bool
    {
        return $this->checkAccess($action);
    }

    public function prepareSignedUriOnViewPublic(): void
    {
        $this->prepareSignedUriOnView();
    }
}
