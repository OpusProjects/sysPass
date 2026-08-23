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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ConfigEncryption;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Config\Ports\ConfigService;
use SP\Application\Crypt\Ports\MasterPassService;
use SP\Application\Crypt\Ports\TemporaryMasterPassService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigEncryption\RefreshController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigEncryption\SaveController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigEncryption\SaveTempController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What these actions do when the ACL says no.
 *
 * Every controller in this family — like every other controller in the `Config*` families — extends
 * `SimpleControllerBase` rather than `ControllerBase`, checking access from `initialize()`, which
 * the base constructor calls. So a refusal here is not an `ActionResponse` an action returns, it is
 * an `UnauthorizedPageException` thrown while *building* the controller. Reaching it needs a
 * `SimpleControllerHelper` rather than the `WebControllerHelper` the shared harness builds, so
 * `simpleControllerHelper()` below assembles one the same way the harness assembles its own (see
 * `WebControllerTestCase::webControllerHelper()`).
 *
 * `applicationForASignedInUser()` cannot be reused as-is either: `SimpleControllerBase::
 * $eventDispatcher` is typed to the concrete, `final` `EventDispatcher`, not
 * `EventDispatcherInterface`, and that assignment runs before `initialize()` ever gets to check the
 * ACL — so a stub of the interface is a property-type `TypeError` on construction, refusal or not.
 * `signedInUserApplication()` mirrors that helper with a real `EventDispatcher` instead.
 *
 * None of the three actions here carries its own `try`/`catch` — none of them pulls in
 * `ConfigTrait`, unlike the `Save` controllers in the other `Config*` families — so whatever a
 * collaborator throws propagates straight past the controller. There is no reachable catch-arm test
 * for any of them.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function refreshingIsRefusedWhenTheAclDenies(): void
    {
        $masterPassService = $this->createMock(MasterPassService::class);
        $masterPassService->expects(self::never())->method('updateConfig');

        $sessionKeyService = $this->createMock(SessionKeyService::class);
        $sessionKeyService->expects(self::never())->method('getSessionKey');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new RefreshController(
            $application,
            $this->simpleControllerHelper($acl, 'configEncryption', 'refresh'),
            $masterPassService,
            $sessionKeyService
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingIsRefusedWhenTheAclDenies(): void
    {
        $masterPassService = $this->createMock(MasterPassService::class);
        $masterPassService->expects(self::never())->method('checkUserUpdateMPass');

        $configService = $this->createMock(ConfigService::class);
        $configService->expects(self::never())->method('getByParam');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new SaveController(
            $application,
            $this->simpleControllerHelper($acl, 'configEncryption', 'save'),
            $masterPassService,
            $configService
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingATemporaryMasterPasswordIsRefusedWhenTheAclDenies(): void
    {
        $temporaryMasterPassService = $this->createMock(TemporaryMasterPassService::class);
        $temporaryMasterPassService->expects(self::never())->method('create');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new SaveTempController(
            $application,
            $this->simpleControllerHelper($acl, 'configEncryption', 'saveTemp'),
            $temporaryMasterPassService
        );
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
        string       $controller = 'controller',
        string       $action = 'action'
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

    /**
     * Mirrors `WebControllerTestCase::applicationForASignedInUser()`, with a real `EventDispatcher`
     * (see the class docblock) in place of a stubbed `EventDispatcherInterface`.
     *
     * @throws Exception
     */
    private function signedInUserApplication(): Application
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

        return new Application($config, new EventDispatcher(), $session);
    }
}
