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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Application\User\Ports\UserProfileService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\ProvidersHelper;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Log\Providers\LogHandler;
use SP\Infrastructure\Adapter\In\Web\Controllers\Index\IndexController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Install\InstallController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Login\LoginController;
use SP\Infrastructure\Adapter\In\Web\Init;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Class InitTest
 *
 * Covers the guards every request passes on the way in.
 *
 * Each one ends the request the same way — a redirect to the page that explains it, and an
 * exception so nothing downstream runs — and they are ordered, so an installation that is not
 * installed must not be asked for a database connection. A guard that redirected without raising
 * would let the controller run anyway and answer into a response already sent.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class InitTest extends UnitaryTestCase
{
    private const UPGRADE_REDIRECT_URI = 'https://example.test?r=upgrade';

    private ConfigData $configData;
    private ConfigFileService|MockObject $configMock;
    private DatabaseUtil|MockObject $databaseUtil;
    private ResponseService|MockObject $response;
    private RequestService|MockObject $request;
    private CsrfHandler|MockObject $csrf;

    /**
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeGeneratesUpgradeKeyAndRedirectsWhenUpgradeIsNeeded(): void
    {
        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion('300.18010101');
        $this->configData->setDatabaseVersion(Version::getVersionStringNormalized());

        $this->databaseUtil->expects(self::once())->method('checkDatabaseConnection')->willReturn(true);
        $this->databaseUtil->expects(self::never())->method('checkDatabaseTables');

        $this->configMock->expects(self::once())->method('generateUpgradeKey');

        $this->response->expects(self::once())
                        ->method('redirect')
                        ->with(self::UPGRADE_REDIRECT_URI)
                        ->willReturnSelf();
        $this->response->expects(self::once())->method('send');

        $this->csrf->expects(self::never())->method('check');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Upgrade needed');

        $this->buildInit()->initialize(IndexController::class);
    }

    /**
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeProceedsWhenNoUpgradeIsNeeded(): void
    {
        $currentVersion = Version::getVersionStringNormalized();

        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);

        $this->databaseUtil->expects(self::once())->method('checkDatabaseConnection')->willReturn(true);
        $this->databaseUtil->expects(self::once())->method('checkDatabaseTables')->willReturn(true);

        $this->configMock->expects(self::never())->method('generateUpgradeKey');

        $this->response->expects(self::never())->method('redirect');

        $this->csrf->expects(self::once())->method('check')->willReturn(true);
        $this->csrf->expects(self::once())->method('initialize');

        // LoginController skips both the user-session bootstrap and the final
        // Session::close() call, keeping the fixture free of PHP session state.
        $this->buildInit()->initialize(LoginController::class);
    }

    /**
     * An installation that has not been installed is sent to the installer, and nothing else is
     * asked of it — there is no database to ask.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeRedirectsToTheInstallerWhenNotInstalled(): void
    {
        $this->configData->setInstalled(false);

        $this->databaseUtil->expects(self::never())->method('checkDatabaseConnection');
        $this->csrf->expects(self::never())->method('check');

        $this->expectsRedirectTo('https://example.test?r=install');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Not installed');

        $this->buildInit()->initialize(IndexController::class);
    }

    /**
     * A database that cannot be reached is its own page, and the schema is not then asked about —
     * the answer would be meaningless.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeRedirectsWhenTheDatabaseCannotBeReached(): void
    {
        $this->givenAnInstalledUpToDateConfig();

        $this->databaseUtil->expects(self::once())->method('checkDatabaseConnection')->willReturn(false);
        $this->databaseUtil->expects(self::never())->method('checkDatabaseTables');

        $this->expectsRedirectTo('https://example.test?r=error%2FdatabaseConnection');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Database connection error');

        $this->buildInit()->initialize(IndexController::class);
    }

    /**
     * Maintenance mode turns everybody away, which is what makes it safe to re-encrypt every
     * account behind it.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeRedirectsWhenInMaintenanceMode(): void
    {
        $this->givenAnInstalledUpToDateConfig();
        $this->configData->setMaintenance(true);

        $this->databaseUtil->expects(self::once())->method('checkDatabaseConnection')->willReturn(true);
        $this->csrf->expects(self::never())->method('check');

        $this->expectsRedirectTo('https://example.test?r=error%2FmaintenanceError');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Maintenance mode');

        $this->buildInit()->initialize(IndexController::class);
    }

    /**
     * A database that is reachable but holds no schema is a different failure from one that cannot
     * be reached, and gets its own page — the two are fixed differently.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeRedirectsWhenTheSchemaIsMissing(): void
    {
        $this->givenAnInstalledUpToDateConfig();

        $this->databaseUtil->expects(self::once())->method('checkDatabaseConnection')->willReturn(true);
        $this->databaseUtil->expects(self::once())->method('checkDatabaseTables')->willReturn(false);

        $this->expectsRedirectTo('https://example.test?r=error%2FdatabaseError');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Database checking error');

        $this->buildInit()->initialize(IndexController::class);
    }

    /**
     * A request whose token does not check out is refused outright — no redirect, since a page to
     * look at is not what a forged request needs, and no new token is issued for it.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testInitializeRefusesARequestWithAnInvalidToken(): void
    {
        $this->givenAnInstalledUpToDateConfig();

        $this->databaseUtil->method('checkDatabaseConnection')->willReturn(true);
        $this->databaseUtil->method('checkDatabaseTables')->willReturn(true);

        $this->csrf->expects(self::once())->method('check')->willReturn(false);
        $this->csrf->expects(self::never())->method('initialize');

        $this->response->expects(self::never())->method('redirect');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Invalid request token');

        $this->buildInit()->initialize(LoginController::class);
    }

    /**
     * A browser reload reloads the configuration, so a setting changed by another session is
     * picked up rather than served from the copy this one started with.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testAReloadReloadsTheConfiguration(): void
    {
        $this->givenAnInstalledUpToDateConfig();

        $this->request = $this->createMock(RequestService::class);
        $this->request->method('checkReload')->willReturn(true);

        $this->databaseUtil->method('checkDatabaseConnection')->willReturn(true);
        $this->databaseUtil->method('checkDatabaseTables')->willReturn(true);
        $this->csrf->method('check')->willReturn(true);

        $this->configMock->expects(self::once())->method('reload');

        $this->buildInit()->initialize(LoginController::class);
    }

    /**
     * The install route is served without any of the guards — it is what a fresh installation is
     * redirected to, so a guard in front of it would be a loop.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testTheInstallerItselfSkipsTheGuards(): void
    {
        $this->configData->setInstalled(false);

        $this->databaseUtil->expects(self::never())->method('checkDatabaseConnection');
        $this->response->expects(self::never())->method('redirect');
        $this->csrf->expects(self::never())->method('check');

        $this->buildInit()->initialize(InstallController::class);
    }

    private function givenAnInstalledUpToDateConfig(): void
    {
        $currentVersion = Version::getVersionStringNormalized();

        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);
    }

    /**
     * Each guard redirects and sends before raising, so the browser is given the page that explains
     * what happened rather than an empty response. The route is url-encoded into the query, so the
     * separator arrives as %2F.
     */
    private function expectsRedirectTo(string $uri): void
    {
        $this->response->expects(self::once())->method('redirect')->with($uri)->willReturnSelf();
        $this->response->expects(self::once())->method('send');
    }

    /**
     * @throws Exception
     */
    private function buildInit(): Init
    {
        $symfonyRequest = new SymfonyRequest();
        $symfonyRequest->query->set('r', 'index/index');

        $router = new Router($symfonyRequest, $this->response);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://example.test');

        $logHandler = new LogHandler(
            $this->application,
            $this->createStub(LoggerInterface::class),
            $this->createStub(LanguageInterface::class),
            $this->createStub(RequestService::class)
        );

        return new Init(
            $this->application,
            new ProvidersHelper($logHandler),
            $this->request,
            $router,
            $this->createStub(AppLockHandler::class),
            $this->csrf,
            $this->createStub(LanguageInterface::class),
            $this->createStub(ItemPresetService::class),
            $this->databaseUtil,
            $this->createStub(UserProfileService::class),
            $uriContext
        );
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseUtil = $this->createMock(DatabaseUtil::class);
        $this->response = $this->createMock(ResponseService::class);
        $this->csrf = $this->createMock(CsrfHandler::class);

        $this->request = $this->createMock(RequestService::class);
        $this->request->method('checkReload')->willReturn(false);
    }

    /**
     * @throws Exception
     */
    protected function buildConfig(): ConfigFileService
    {
        $this->configData = new ConfigData([ConfigDataInterface::PASSWORD_SALT => self::$faker->sha1()]);

        $this->configMock = $this->createMock(ConfigFileService::class);
        $this->configMock->method('getConfigData')->willReturn($this->configData);

        return $this->configMock;
    }
}
