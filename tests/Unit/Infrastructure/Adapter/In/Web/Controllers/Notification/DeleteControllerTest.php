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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Common\Enums\ResponseStatus;
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
use SP\Infrastructure\Adapter\In\Web\Controllers\Notification\DeleteController;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * DeleteController::deleteAction() refuses the request outright when the ACL denies
 * NOTIFICATION_DELETE — the very first check the action makes, before any service call. The
 * integration suite can never reach this branch: its AclInterface double is permanently open
 * (see IntegrationTestCase::getMockedDefinitions()). Building the controller through its real
 * constructor with a closed ACL, the way ControllerBaseTest covers the same kind of denial on the
 * base class, is the only way to exercise it.
 */
#[Group('unitary')]
class DeleteControllerTest extends UnitaryTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function aRequestIsRefusedWhenTheAclDeniesTheDeleteAction(): void
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::never())->method('delete');
        $notificationService->expects(self::never())->method('deleteAdmin');
        $notificationService->expects(self::never())->method('deleteByIdBatch');
        $notificationService->expects(self::never())->method('deleteAdminBatch');

        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);

        $controller = $this->buildController($notificationService, $acl);

        $response = $controller->deleteAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * Builds a real DeleteController through its actual constructor chain (NotificationSaveBase ->
     * ControllerBase), exactly as ControllerBaseTest does for the base class's own guards. Every
     * collaborator besides the ACL and the notification service is a stub that is never asserted
     * on — the request never gets past the ACL check to use them.
     *
     * @throws Exception
     */
    private function buildController(NotificationService $notificationService, AclInterface $acl): DeleteController
    {
        $userDto = new UserDto(
            id: 7,
            userGroupId: 2,
            login: 'jdoe',
            ssoLogin: 'jdoe@sso.example',
            isAdminApp: false,
            isAdminAcc: false
        );

        $session = $this->createStub(SessionContext::class);
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getAuthCompleted')->willReturn(true);
        $session->method('getUserData')->willReturn($userDto);
        $session->method('getUserProfile')->willReturn(new ProfileData());

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isDemoEnabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn('the-password-salt');
        $configData->method('isAuthBasicEnabled')->willReturn(false);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $application = new Application($config, $eventDispatcher, $session);

        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn(null);
        $request->method('analyzeArray')->willReturn(null);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        $browser = $this->createStub(BrowserAuthService::class);

        $template = $this->createStub(TemplateInterface::class);

        $router = new Router(new Request(), $this->createStub(ResponseService::class));

        $routeContextData = new RouteContextData('notification', 'delete', 'deleteAction', []);

        $extensionChecker = new PhpExtensionChecker();

        $cryptPki = $this->createStub(CryptPKIHandler::class);

        $layoutHelper = new LayoutHelper(
            $application,
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

        return new DeleteController($application, $webControllerHelper, $notificationService);
    }
}
