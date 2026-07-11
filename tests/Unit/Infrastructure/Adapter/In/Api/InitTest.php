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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Context\ContextException;
use SP\Infrastructure\Language;
use SP\Infrastructure\ProvidersHelper;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Infrastructure\Http\Ports\RequestService;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Log\Providers\LogHandler;
use SP\Infrastructure\Adapter\In\Api\Init;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Class InitTest
 *
 * Covers the upgrade-needed wiring in the api Init: mirroring the legacy API
 * behaviour, detecting an outdated config version must persist the upgrade
 * key and reject the request, without ever reaching the database checks.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class InitTest extends UnitaryTestCase
{
    private ConfigData $configData;
    private ConfigFileService|MockObject $configMock;
    private DatabaseUtil|MockObject $databaseUtil;

    /**
     * @throws Exception
     * @throws ContextException
     * @throws InitializationException
     */
    public function testInitializeGeneratesUpgradeKeyAndThrowsWhenUpgradeIsNeeded(): void
    {
        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion('300.18010101');
        $this->configData->setDatabaseVersion(Version::getVersionStringNormalized());

        $this->configMock->expects(self::once())->method('generateUpgradeKey');

        $this->databaseUtil->expects(self::never())->method('checkDatabaseConnection');
        $this->databaseUtil->expects(self::never())->method('checkDatabaseTables');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Upgrade needed');

        $this->buildInit()->initialize('test');
    }

    /**
     * @throws Exception
     * @throws ContextException
     */
    private function buildInit(): Init
    {
        $router = new Router(new SymfonyRequest(), $this->createStub(ResponseService::class));

        $logHandler = new LogHandler(
            $this->application,
            $this->createStub(LoggerInterface::class),
            $this->createStub(LanguageInterface::class),
            $this->createStub(RequestService::class)
        );

        // Language is final (can't be doubled) and Init stores it behind the
        // concrete class rather than LanguageInterface, so a real instance is
        // required here.
        $language = new Language(
            $this->context,
            $this->configData,
            $this->createStub(RequestService::class),
            APP_PATH . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'locales'
        );

        return new Init(
            $this->application,
            new ProvidersHelper($logHandler),
            $this->createStub(RequestService::class),
            $router,
            $this->createStub(AppLockHandler::class),
            $language,
            $this->databaseUtil
        );
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseUtil = $this->createMock(DatabaseUtil::class);
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
