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

namespace SP\Tests\Core;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Log\LoggerInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Core\ProvidersHelper;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Log\Providers\LogHandler;
use SP\Tests\Stubs\ModuleBaseStub;
use SP\Tests\UnitaryTestCase;

/**
 * Class ModuleBaseTest
 *
 * Covers ModuleBase::checkUpgradeNeeded(), the detection logic that decides
 * whether the app/database version stored in the config lags behind the
 * version shipped with the running code.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ModuleBaseTest extends UnitaryTestCase
{
    private const OUTDATED_VERSION = '300.18010101';

    private ConfigData $configData;

    /**
     * A fresh install writes the current version into both the app and the
     * database version fields (see Installer::run()), so a freshly installed
     * instance must never be routed to the upgrade flow.
     *
     * @throws Exception
     */
    public function testCheckUpgradeNeededIsFalseWhenVersionsMatchCurrent(): void
    {
        $currentVersion = Version::getVersionStringNormalized();

        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);

        self::assertFalse($this->buildModule()->checkUpgradeNeededForTest());
    }

    /**
     * @throws Exception
     */
    public function testCheckUpgradeNeededIsTrueWhenAppVersionIsOutdated(): void
    {
        $this->configData->setAppVersion(self::OUTDATED_VERSION);
        $this->configData->setDatabaseVersion(Version::getVersionStringNormalized());

        self::assertTrue($this->buildModule()->checkUpgradeNeededForTest());
    }

    /**
     * @throws Exception
     */
    public function testCheckUpgradeNeededIsTrueWhenDatabaseVersionIsOutdated(): void
    {
        $this->configData->setAppVersion(Version::getVersionStringNormalized());
        $this->configData->setDatabaseVersion(self::OUTDATED_VERSION);

        self::assertTrue($this->buildModule()->checkUpgradeNeededForTest());
    }

    /**
     * A config predating the database version field (e.g. an upgrade from a very
     * old version) must be treated as needing an upgrade rather than skipping it.
     *
     * @throws Exception
     */
    public function testCheckUpgradeNeededIsTrueWhenDatabaseVersionIsMissing(): void
    {
        $this->configData->setAppVersion(Version::getVersionStringNormalized());
        // Leave DATABASE_VERSION unset: ConfigData::getDatabaseVersion() returns ''.

        self::assertTrue($this->buildModule()->checkUpgradeNeededForTest());
    }

    /**
     * @throws Exception
     */
    public function testCheckUpgradeNeededIsTrueWhenAppVersionIsExplicitlyEmpty(): void
    {
        $this->configData->setAppVersion('');
        $this->configData->setDatabaseVersion(Version::getVersionStringNormalized());

        self::assertTrue($this->buildModule()->checkUpgradeNeededForTest());
    }

    /**
     * @throws Exception
     */
    private function buildModule(): ModuleBaseStub
    {
        $logHandler = new LogHandler(
            $this->application,
            $this->createStub(LoggerInterface::class),
            $this->createStub(LanguageInterface::class),
            $this->createStub(RequestService::class)
        );

        return new ModuleBaseStub($this->application, new ProvidersHelper($logHandler));
    }

    /**
     * @throws Exception
     */
    protected function buildConfig(): ConfigFileService
    {
        $this->configData = new ConfigData([ConfigDataInterface::PASSWORD_SALT => self::$faker->sha1()]);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($this->configData);

        return $config;
    }
}
