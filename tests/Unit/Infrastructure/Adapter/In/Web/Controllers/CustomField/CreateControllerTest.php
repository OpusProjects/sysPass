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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\CustomField;

use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception as MockException;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\CustomField\Ports\CustomFieldDefinitionService;
use SP\Application\CustomField\Ports\CustomFieldTypeService;
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
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * CreateController::createAction() refuses the request outright when the ACL denies
 * CUSTOMFIELD_CREATE — the very first check the action makes, before the form is ever built.
 * The integration suite can never reach this branch: its AclInterface double is permanently open
 * (see IntegrationTestCase::getMockedDefinitions()). Building the controller through its real
 * constructor with a closed ACL, the way DeleteControllerTest covers the same kind of denial for
 * Notification, is the only way to exercise it.
 *
 * It also covers the action's own catch(Exception) — a failure while assembling the form (here,
 * the type list) is reported as an error instead of an uncaught 500.
 */
#[Group('unitary')]
class CreateControllerTest extends UnitaryTestCase
{
    /**
     * @throws MockException
     */
    #[Test]
    public function aRequestIsRefusedWhenTheAclDeniesTheCreateAction(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('getById');

        $customFieldTypeService = $this->createMock(CustomFieldTypeService::class);
        $customFieldTypeService->expects(self::never())->method('getAll');

        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);

        $controller = $this->buildController($customFieldDefService, $customFieldTypeService, $acl);

        $response = $controller->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * A failure while building the type/module selects (here, the type list) is caught and
     * reported as an ordinary error response rather than propagating as an uncaught exception.
     *
     * @throws MockException
     */
    #[Test]
    public function aFailureWhileBuildingTheFormIsReportedAsAnError(): void
    {
        $customFieldDefService = $this->createStub(CustomFieldDefinitionService::class);

        $customFieldTypeService = $this->createStub(CustomFieldTypeService::class);
        $customFieldTypeService->method('getAll')->willThrowException(new Exception('the type list is broken'));

        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(true);
        $acl->method('getRouteFor')->willReturn('items/manage');

        $controller = $this->buildController($customFieldDefService, $customFieldTypeService, $acl);

        $response = $controller->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the type list is broken', $response->subject);
    }

    /**
     * Builds a real CreateController through its actual constructor chain
     * (CustomFieldViewBase -> ControllerBase), exactly as DeleteControllerTest does for
     * Notification's own base class. Every collaborator besides the ACL and the two custom
     * field services is a stub that is never asserted on.
     *
     * @throws MockException
     */
    private function buildController(
        CustomFieldDefinitionService $customFieldDefService,
        CustomFieldTypeService       $customFieldTypeService,
        AclInterface                 $acl
    ): CreateController {
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

        $routeContextData = new RouteContextData('customField', 'create', 'createAction', []);

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

        return new CreateController(
            $application,
            $webControllerHelper,
            $customFieldDefService,
            $customFieldTypeService
        );
    }
}
