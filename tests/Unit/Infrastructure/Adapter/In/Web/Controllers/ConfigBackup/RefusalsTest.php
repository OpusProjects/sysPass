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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Export\Ports\BackupFileService;
use SP\Application\Export\Ports\XmlExportService;
use SP\Application\Export\Ports\XmlVerifyService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup\DownloadBackupAppController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup\DownloadBackupDbController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup\DownloadExportController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup\FileBackupController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigBackup\XmlExportController;
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
 * `SimpleControllerBase` rather than `ControllerBase`. Four of the five check access from
 * `initialize()`, which the base constructor calls; `XmlExportController` calls `checks()` and
 * `checkAccess()` directly from its own constructor, right after `parent::__construct()` — same
 * effect, just inlined rather than delegated to `initialize()`. Either way, a refusal here is not an
 * `ActionResponse` an action returns, it is an `UnauthorizedPageException` thrown while *building*
 * the controller. Reaching it needs a `SimpleControllerHelper` rather than the `WebControllerHelper`
 * the shared harness builds, so `simpleControllerHelper()` below assembles one the same way the
 * harness assembles its own (see `WebControllerTestCase::webControllerHelper()`).
 *
 * `applicationForASignedInUser()` cannot be reused as-is either: `SimpleControllerBase::
 * $eventDispatcher` is typed to the concrete, `final` `EventDispatcher`, not
 * `EventDispatcherInterface`, and that assignment runs before either guard ever gets to check the
 * ACL — so a stub of the interface is a property-type `TypeError` on construction, refusal or not.
 * `signedInUserApplication()` mirrors that helper with a real `EventDispatcher` instead.
 *
 * None of the five actions here carries its own `try`/`catch` — none of them pulls in
 * `ConfigTrait`, unlike the `Save` controllers in the other `Config*` families — so whatever a
 * collaborator throws propagates straight past the controller. There is no reachable catch-arm test
 * for any of them.
 *
 * `PathsContext` (used by four of the five) is a `final readonly` value holder with no
 * dependencies of its own — a real, empty instance stands in for it rather than a double, since the
 * refusal happens before anything would read a path out of it.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function downloadingTheAppBackupIsRefusedWhenTheAclDenies(): void
    {
        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new DownloadBackupAppController(
            $application,
            $this->simpleControllerHelper($acl, 'configBackup', 'downloadBackupApp'),
            new PathsContext()
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function downloadingTheDbBackupIsRefusedWhenTheAclDenies(): void
    {
        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new DownloadBackupDbController(
            $application,
            $this->simpleControllerHelper($acl, 'configBackup', 'downloadBackupDb'),
            new PathsContext()
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function downloadingTheExportIsRefusedWhenTheAclDenies(): void
    {
        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new DownloadExportController(
            $application,
            $this->simpleControllerHelper($acl, 'configBackup', 'downloadExport'),
            new PathsContext()
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function runningTheFileBackupIsRefusedWhenTheAclDenies(): void
    {
        $fileBackupService = $this->createMock(BackupFileService::class);
        $fileBackupService->expects(self::never())->method('doBackup');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new FileBackupController(
            $application,
            $this->simpleControllerHelper($acl, 'configBackup', 'fileBackup'),
            $fileBackupService,
            new PathsContext()
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function runningTheXmlExportIsRefusedWhenTheAclDenies(): void
    {
        $xmlExportService = $this->createMock(XmlExportService::class);
        $xmlExportService->expects(self::never())->method('export');

        $xmlVerifyService = $this->createMock(XmlVerifyService::class);
        $xmlVerifyService->expects(self::never())->method('verify');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new XmlExportController(
            $application,
            $this->simpleControllerHelper($acl, 'configBackup', 'xmlExport'),
            $xmlExportService,
            $xmlVerifyService,
            new PathsContext()
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
