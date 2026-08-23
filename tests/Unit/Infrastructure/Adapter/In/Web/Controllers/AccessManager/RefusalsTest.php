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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\AccessManager;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Account\Ports\PublicLinkService;
use SP\Application\Application;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\AccessManager\IndexController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AuthTokenGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\PublicLinkGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserGroupGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserProfileGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\TabsGridHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What this action does when the ACL says no.
 *
 * `IndexController` does not check access the way most controllers do — it does not have a single
 * `if (!checkUserAccess()) { return ActionResponse::error(...); }` guard, so there is no "refused"
 * response to look for and, since nothing here wraps a `try`/`catch`, no catch-arm test either. What
 * it does instead is call `ControllerBase::checkAccess()` once per module (users, groups, profiles,
 * auth tokens, public links) to decide whether that module's tab gets built at all — an
 * administrator sees all of them, a denied, non-admin caller sees an empty tab set, and the action
 * still answers `ActionResponse::ok(...)`.
 *
 * The integration harness's ACL double answers `true` to everything, so no request dispatched
 * through it can ever see an empty tab set — this is the only place that ACL gate is exercised. The
 * assertion here is that denying every module means none of the five services is ever asked to
 * search.
 *
 * `getGridTabs()` always reaches `$this->request->analyzeInt('tabIndex', 0)` and hands the result
 * straight to `TabsGridHelper::renderTabs(string $route, int $activeTab = 0)` — unlike the shared
 * harness's own `webControllerHelper()`, whose `RequestService` stub answers every `analyzeInt(...)`
 * call with `null` regardless of the default given, which is a `TypeError` against that
 * non-nullable parameter. `webControllerHelperWithTabIndex()` below mirrors the shared one but fixes
 * that one method to answer `0`, since this controller cannot exit before reaching it.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * A real (final, so undoubled) grid — the action never reaches any of them once every
     * `checkAccess()` call has already returned false, but the controller's constructor still
     * requires concrete instances of all of them.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     * @throws Exception
     */
    private function grid(string $class, Application $application): object
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new $class(
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
    private function tabsGridHelper(Application $application): TabsGridHelper
    {
        return new TabsGridHelper(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class)
        );
    }

    /**
     * Mirrors `WebControllerTestCase::webControllerHelper()`, but with a `RequestService` stub
     * whose `analyzeInt()` answers `0` rather than `null` — see the class docblock for why this
     * controller needs that.
     *
     * @throws Exception
     */
    private function webControllerHelperWithTabIndex(
        AclInterface $acl,
        Application  $application,
        string       $controller,
        string       $action
    ): WebControllerHelper {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn(null);
        $request->method('analyzeArray')->willReturn(null);
        $request->method('analyzeInt')->willReturn(0);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

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
            new RouteContextData($controller, $action, $action . 'Action', [])
        );

        return new WebControllerHelper(
            $simpleControllerHelper,
            $template,
            $this->createStub(BrowserAuthService::class),
            $layoutHelper
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function noModulesTabIsBuiltWhenTheAclDeniesEveryModule(): void
    {
        $userService = $this->createMock(UserService::class);
        $userService->expects(self::never())->method('search');

        $userGroupService = $this->createMock(UserGroupService::class);
        $userGroupService->expects(self::never())->method('search');

        $userProfileService = $this->createMock(UserProfileService::class);
        $userProfileService->expects(self::never())->method('search');

        $authTokenService = $this->createMock(AuthTokenService::class);
        $authTokenService->expects(self::never())->method('search');

        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new IndexController(
            $application,
            $this->webControllerHelperWithTabIndex($acl, $application, 'accessManager', 'index'),
            $this->tabsGridHelper($application),
            $this->grid(UserGrid::class, $application),
            $this->grid(UserGroupGrid::class, $application),
            $this->grid(UserProfileGrid::class, $application),
            $this->grid(AuthTokenGrid::class, $application),
            $this->grid(PublicLinkGrid::class, $application),
            $userService,
            $userGroupService,
            $userProfileService,
            $authTokenService,
            $publicLinkService
        ))->indexAction();

        self::assertSame(ResponseStatus::OK, $response->status);
    }
}
