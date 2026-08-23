<?php
declare(strict_types=1);
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

namespace SP\Tests\Support;

use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use Symfony\Component\HttpFoundation\Request;

/**
 * The stub graph a web controller needs before it will construct, in one place.
 *
 * Two things about these controllers can only be reached from a unit test. The first is a refusal:
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so no
 * request dispatched through that harness can be denied, and a test that appears to show one is
 * passing for some other reason. The second is the `catch` arm every action carries, which needs a
 * collaborator that throws.
 *
 * Reaching either meant assembling a dozen stubs — theme, router, layout helper, template, PKI,
 * request, session, config — which is why it had been done once, for one controller. That
 * assembly lives here now, so a test for a refused request is the three lines that describe the
 * refusal rather than ninety that describe the scaffolding.
 */
abstract class WebControllerTestCase extends UnitaryTestCase
{
    /**
     * An ACL that refuses everything, which is the point of most tests built on this.
     *
     * @throws Exception
     */
    final protected function aclThatRefuses(): AclInterface
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);

        return $acl;
    }

    /**
     * @throws Exception
     */
    final protected function aclThatAllows(): AclInterface
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);
        $acl->method('getRouteFor')->willReturn('a/route');

        return $acl;
    }

    /**
     * An application whose session holds a signed-in, non-administrator user — the caller a
     * refusal test is about, since an administrator is waved through most checks.
     *
     * @throws Exception
     */
    final protected function applicationForASignedInUser(): Application
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
                isAdminApp: false,
                isAdminAcc: false
            )
        );
        $session->method('getUserProfile')->willReturn(new ProfileData());

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isDemoEnabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn('the-password-salt');
        $configData->method('isAuthBasicEnabled')->willReturn(false);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        return new Application($config, $this->createStub(EventDispatcherInterface::class), $session);
    }

    /**
     * @throws Exception
     */
    final protected function webControllerHelper(
        AclInterface $acl,
        Application  $application,
        string       $controller = 'controller',
        string       $action = 'action'
    ): WebControllerHelper {
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
}
