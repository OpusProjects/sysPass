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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ConfigLdap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Auth\Ports\LdapCheckService;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Import\Ports\LdapImportService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigLdap\CheckController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigLdap\CheckImportController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigLdap\ImportController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigLdap\SaveController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What these actions do when the ACL says no.
 *
 * Every controller in this family extends `SimpleControllerBase` rather than `ControllerBase`, and
 * checks access from `initialize()`, which the base constructor calls — so a refusal here is not an
 * `ActionResponse` an action returns, it is an `UnauthorizedPageException` thrown while *building*
 * the controller, before any injected LDAP service or the `PhpExtensionChecker::checkLdap()` call
 * that follows it in `initialize()` is ever reached. Reaching it needs a `SimpleControllerHelper`
 * rather than the `WebControllerHelper` the shared harness builds, so `simpleControllerHelper()`
 * below assembles one the same way the harness assembles its own (see
 * `WebControllerTestCase::webControllerHelper()`).
 *
 * `applicationForASignedInUser()` cannot be reused as-is either: `SimpleControllerBase::
 * $eventDispatcher` is typed to the concrete, `final` `EventDispatcher`, not
 * `EventDispatcherInterface`, and that assignment runs before `initialize()` ever gets to check the
 * ACL — so a stub of the interface is a property-type `TypeError` on construction, refusal or not.
 * `signedInUserApplication()` mirrors that helper with a real `EventDispatcher` instead.
 *
 * `CheckController::checkAction()`, `CheckImportController::checkImportAction()` and
 * `ImportController::importAction()` (the LDAP user/group import, not `ConfigImport`) carry no
 * `catch` of their own — whatever their service throws propagates past the controller — so only
 * `SaveController` gets a failure-path test, via the `catch` inside `ConfigTrait::saveConfig()`.
 * That one is not reached unconditionally either: with LDAP disabled in both the request and the
 * stored config, `saveAction()` answers "No changes" before ever calling `saveConfig()`, so the
 * catch-arm test needs the stored config to already have LDAP enabled.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function checkingIsRefusedWhenTheAclDenies(): void
    {
        $ldapCheckService = $this->createMock(LdapCheckService::class);
        $ldapCheckService->expects(self::never())->method('getObjects');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new CheckController(
            $application,
            $this->simpleControllerHelper($acl, 'configLdap', 'check'),
            $ldapCheckService,
            $this->createStub(TemplateInterface::class)
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function checkingAnImportIsRefusedWhenTheAclDenies(): void
    {
        $ldapCheckService = $this->createMock(LdapCheckService::class);
        $ldapCheckService->expects(self::never())->method('getObjects');
        $ldapCheckService->expects(self::never())->method('getObjectsByFilter');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new CheckImportController(
            $application,
            $this->simpleControllerHelper($acl, 'configLdap', 'checkImport'),
            $ldapCheckService,
            $this->createStub(TemplateInterface::class)
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function importingIsRefusedWhenTheAclDenies(): void
    {
        $ldapImportService = $this->createMock(LdapImportService::class);
        $ldapImportService->expects(self::never())->method('importUsers');
        $ldapImportService->expects(self::never())->method('importGroups');

        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new ImportController(
            $application,
            $this->simpleControllerHelper($acl, 'configLdap', 'import'),
            $ldapImportService
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingIsRefusedWhenTheAclDenies(): void
    {
        $application = $this->signedInUserApplication();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        new SaveController(
            $application,
            $this->simpleControllerHelper($acl, 'configLdap', 'save')
        );
    }

    /**
     * What `ConfigTrait::saveConfig()` does when the write behind it fails.
     *
     * Nothing in the mocked integration harness ever fails to save, so this catch arm — the one
     * shared by every controller that pulls in `ConfigTrait` — went untested. Here the stored config
     * already has LDAP enabled (and the request carries no `ldap_enabled` flag, so the stub's `bool`
     * default of `false` reads as "disable it"), which is what gets `saveAction()` past the "No
     * changes" early return and into `saveConfig()`. `ConfigFileService::save()` then throws, and the
     * assertion is that the caller is told rather than left with a blank page or an escaping fatal.
     * Unlike a plain controller `catch`, this one answers with a fixed subject and carries the
     * exception's message as `extra` instead.
     *
     * @throws Exception
     */
    #[Test]
    public function savingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $application = $this->applicationWhoseConfigSaveThrows();
        $acl = $this->aclThatAllows();

        $response = (new SaveController(
            $application,
            $this->simpleControllerHelper($acl, 'configLdap', 'save')
        ))->saveAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('Error while saving the configuration', $response->subject);
        self::assertSame('the configuration file could not be written', $response->extra);
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
        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($this->demoDisabledConfigData());

        return new Application($config, new EventDispatcher(), $this->signedInUserSession());
    }

    /**
     * Mirrors `signedInUserApplication()`, but swaps in a `ConfigFileService` double whose `save()`
     * fails, to reach `ConfigTrait::saveConfig()`'s catch arm. The config data also reports LDAP as
     * already enabled, so `saveAction()`'s "disable it" branch runs and falls through to the save
     * instead of short-circuiting with "No changes".
     *
     * @throws Exception
     */
    private function applicationWhoseConfigSaveThrows(): Application
    {
        $configData = $this->demoDisabledConfigData();
        $configData->method('isLdapEnabled')->willReturn(true);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);
        $config->method('save')->willThrowException(
            new RuntimeException('the configuration file could not be written')
        );

        return new Application($config, new EventDispatcher(), $this->signedInUserSession());
    }

    /**
     * @throws Exception
     */
    private function signedInUserSession(): SessionContext
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

        return $session;
    }

    /**
     * @throws Exception
     */
    private function demoDisabledConfigData(): ConfigDataInterface
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('isDemoEnabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn('the-password-salt');
        $configData->method('isAuthBasicEnabled')->willReturn(false);

        return $configData;
    }
}
