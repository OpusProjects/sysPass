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

namespace SP\Tests\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Application\User\Ports\UserProfileService;
use SP\Core\Bootstrap\Router;
use SP\Core\ProvidersHelper;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\Log\Providers\LogHandler;
use SP\Infrastructure\Adapter\In\Web\Controllers\Index\IndexController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Login\LoginController;
use SP\Infrastructure\Adapter\In\Web\Init;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Class InitTest
 *
 * Covers the upgrade-needed wiring in the web Init: detecting an outdated
 * config version must persist the upgrade key and redirect to the upgrade
 * route, while an up-to-date config must fall through unaffected.
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
