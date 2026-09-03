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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ConfigAuth;

use SP\Application\Config\Ports\ConfigBackupService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
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
use SP\Infrastructure\Adapter\In\Web\Controllers\ConfigAuth\SaveController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What this action does when the ACL says no.
 *
 * `ConfigAuth` has a single controller, and it — like every other controller in the `Config*`
 * families — extends `SimpleControllerBase` rather than `ControllerBase`, checking access from
 * `initialize()`, which the base constructor calls. So the refusal here is not an `ActionResponse`
 * an action returns, it is an `UnauthorizedPageException` thrown while *building* the controller.
 * Reaching it needs a `SimpleControllerHelper` rather than the `WebControllerHelper` the shared
 * harness builds, so `simpleControllerHelper()` below assembles one the same way the harness
 * assembles its own (see `WebControllerTestCase::webControllerHelper()`).
 *
 * `applicationForASignedInUser()` cannot be reused as-is either: `SimpleControllerBase::
 * $eventDispatcher` is typed to the concrete, `final` `EventDispatcher`, not
 * `EventDispatcherInterface`, and that assignment runs before `initialize()` ever gets to check the
 * ACL — so a stub of the interface is a property-type `TypeError` on construction, refusal or not.
 * `signedInUserApplication()` mirrors that helper with a real `EventDispatcher` instead.
 *
 * `SaveController` here takes no collaborator of its own — only the `Application` and helper every
 * `SimpleControllerBase` needs — so there is nothing to assert was "never called" beyond the
 * exception itself.
 *
 * `saveAction()` delegates to `ConfigTrait::saveConfig()`, which does carry a `catch` — around
 * `ConfigFileService::save()` — so that gets a test for the failure path too.
 */
/**
 * Saving configuration keeps the configuration it replaced.
 *
 * `ConfigBackupService::backup()` has existed since this rewrite was imported and was called from
 * nowhere, so `config_backup` was never written — and the "Download config backup" link the
 * Information page renders answered "Unable to retrieve the configuration" every time, on every
 * installation. The integration tests around that download all seed the parameter by hand with
 * `#[InjectConfigParam]`, which is the shape of a feature nothing produces.
 *
 * The backup is taken in `ConfigTrait::saveConfig()`, which every one of the nine configuration
 * screens goes through, so one controller stands for all of them here.
 */
#[Group('unitary')]
class ConfigBackupOnSaveTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function savingKeepsTheConfigurationItReplaces(): void
    {
        $configBackup = $this->createMock(ConfigBackupService::class);
        $configBackup->expects(self::once())->method('backup');

        (new SaveController(
            $this->signedInUserApplication(),
            $this->simpleControllerHelper($this->aclThatAllows(), 'configAuth', 'save'),
            $configBackup
        ))->saveAction();
    }

    /**
     * And takes it *before* the save, or it would keep the configuration it was replacing it with.
     *
     * `ConfigFileService::getConfigData()` answers a clone and `save()` has not run yet, so what
     * reaches `backup()` is the stored configuration rather than the one being written. Ordering is
     * the only thing that makes that true, so it is asserted directly.
     *
     * @throws Exception
     */
    #[Test]
    public function theBackupIsTakenBeforeTheNewConfigurationIsWritten(): void
    {
        $order = [];

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($this->demoDisabledConfigData());
        $config->method('save')->willReturnCallback(
            function () use (&$order, &$config): ConfigFileService {
                $order[] = 'save';

                return $config;
            }
        );

        $configBackup = $this->createStub(ConfigBackupService::class);
        $configBackup->method('backup')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'backup';
            }
        );

        (new SaveController(
            new Application($config, new EventDispatcher(), $this->signedInUserSession()),
            $this->simpleControllerHelper($this->aclThatAllows(), 'configAuth', 'save'),
            $configBackup
        ))->saveAction();

        self::assertSame(['backup', 'save'], $order);
    }

    /**
     * A demo instance refuses the save, and stores no backup of a configuration it did not replace.
     *
     * @throws Exception
     */
    #[Test]
    public function aRefusedSaveKeepsNothing(): void
    {
        $demoConfigData = $this->createStub(ConfigDataInterface::class);
        $demoConfigData->method('isDemoEnabled')->willReturn(true);
        $demoConfigData->method('getPasswordSalt')->willReturn('the-password-salt');

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($demoConfigData);

        $configBackup = $this->createMock(ConfigBackupService::class);
        $configBackup->expects(self::never())->method('backup');

        $response = (new SaveController(
            new Application($config, new EventDispatcher(), $this->signedInUserSession()),
            $this->simpleControllerHelper($this->aclThatAllows(), 'configAuth', 'save'),
            $configBackup
        ))->saveAction();

        self::assertSame(ResponseStatus::WARNING, $response->status);
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
