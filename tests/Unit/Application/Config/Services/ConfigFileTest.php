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

namespace SP\Tests\Unit\Application\Config\Services;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Exceptions\ContextException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Services\ConfigFile;
use SP\Domain\Core\Exceptions\ConfigException;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Domain\Storage\Ports\XmlFileStorageService;
use SP\Domain\Core\Exceptions\FileException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class ConfigFileTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ConfigFileTest extends UnitaryTestCase
{
    private XmlFileStorageService|MockObject $fileStorageService;
    private FileCacheService|MockObject      $fileCacheService;

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithProvided()
    {
        $this->fileCacheService
            ->expects(self::never())
            ->method('exists');

        $this->fileStorageService
            ->expects(self::never())
            ->method('getFileTime');

        $this->fileStorageService
            ->expects(self::never())
            ->method('save');

        $this->fileCacheService
            ->expects(self::never())
            ->method('save');

        $attributes = [
            ConfigDataInterface::CONFIG_HASH => self::$faker->sha1(),
            ConfigDataInterface::LOG_EVENTS => self::$faker->boolean(),
            ConfigDataInterface::CONFIG_DATE => self::$faker->unixTime()
        ];

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context,
            new ConfigData($attributes)
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithCache()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(true);

        $time = time();

        $this->fileStorageService
            ->expects(self::once())
            ->method('getFileTime')
            ->willReturn($time);

        $this->fileCacheService
            ->expects(self::once())
            ->method('isExpiredDate')
            ->with($time)
            ->willReturn(false);

        $this->fileCacheService
            ->expects(self::once())
            ->method('loadWith')
            ->with(ConfigData::class)
            ->willReturn($this->config->getConfigData());

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithCacheAndNoAttributes()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(true);

        $time = time();

        $this->fileStorageService
            ->expects(self::once())
            ->method('getFileTime')
            ->willReturn($time);

        $this->fileCacheService
            ->expects(self::once())
            ->method('isExpiredDate')
            ->with($time)
            ->willReturn(false);

        $this->fileCacheService
            ->expects(self::once())
            ->method('loadWith')
            ->with(ConfigData::class)
            ->willReturn(new ConfigData());

        $this->fileCacheService
            ->expects(self::once())
            ->method('delete');

        $this->ensureConfigFileIsUsed();

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @return void
     */
    private function ensureConfigFileIsUsed(): void
    {
        $configData = new ConfigData();

        $this->fileStorageService
            ->expects(self::once())
            ->method('load')
            ->with('config')
            ->willReturn($configData->getAttributes());

        $this->fileCacheService
            ->expects(self::once())
            ->method('save')
            ->with($configData);
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithExistingFile()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->ensureConfigFileIsUsed();

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithGenerateNewConfig()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->fileStorageService
            ->expects(self::never())
            ->method('getFileTime');

        $this->fileStorageService
            ->expects(self::once())
            ->method('load')
            ->with('config')
            ->willThrowException(FileException::error('test'));

        $this->fileStorageService
            ->expects(self::once())
            ->method('save')
            ->with(self::isArray(), 'config');

        $this->fileCacheService
            ->expects(self::once())
            ->method('save')
            ->with(self::anything());

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithExceptionFromCache()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(true);

        $time = time();

        $this->fileStorageService
            ->expects(self::once())
            ->method('getFileTime')
            ->willReturn($time);

        $this->fileCacheService
            ->expects(self::once())
            ->method('isExpiredDate')
            ->with($time)
            ->willReturn(false);

        $this->fileCacheService
            ->expects(self::once())
            ->method('loadWith')
            ->with(ConfigData::class)
            ->willThrowException(FileException::error('test'));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('test');

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithExceptionFromCacheExpired()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(true);

        $time = time();

        $this->fileStorageService
            ->expects(self::exactly(1))
            ->method('getFileTime')
            ->willReturn($time);

        $this->fileCacheService
            ->expects(self::once())
            ->method('isExpiredDate')
            ->with($time)
            ->willThrowException(FileException::error('test'));

        $this->fileCacheService
            ->expects(self::never())
            ->method('loadWith');

        $this->ensureConfigFileIsUsed();

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testInitializeWithExceptionCacheDelete()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(true);

        $time = time();

        $this->fileStorageService
            ->expects(self::once())
            ->method('getFileTime')
            ->willReturn($time);

        $this->fileCacheService
            ->expects(self::once())
            ->method('isExpiredDate')
            ->with($time)
            ->willReturn(false);

        $this->fileCacheService
            ->expects(self::once())
            ->method('loadWith')
            ->with(ConfigData::class)
            ->willReturn(new ConfigData());

        $this->fileCacheService
            ->expects(self::once())
            ->method('delete')
            ->willThrowException(FileException::error('test'));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('test');

        new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );
    }

    /**
     * @throws ConfigException
     * @throws Exception
     * @throws FileException
     */
    public function testSave()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->fileStorageService
            ->expects(self::never())
            ->method('getFileTime');

        $this->fileStorageService
            ->expects(self::once())
            ->method('load')
            ->willThrowException(FileException::error('test'));

        $configData = $this->createMock(ConfigDataInterface::class);

        $this->fileStorageService
            ->expects(self::exactly(2))
            ->method('save')
            ->with(self::isArray(), 'config');

        $this->fileCacheService
            ->expects(self::exactly(2))
            ->method('save')
            ->with(self::anything());

        $configFile = new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );

        $configData->expects(self::once())
                   ->method('setConfigDate');
        $configData->expects(self::once())
                   ->method('setConfigSaver');
        $configData->expects(self::once())
                   ->method('setConfigHash');
        $configData->expects(self::once())
                   ->method('getAttributes')
                   ->willReturn([]);

        $configFile->save($configData);

        $this->assertEquals($configData, $configFile->getConfigData());
    }

    /**
     * @throws ConfigException
     * @throws Exception
     * @throws FileException
     */
    public function testSaveWithoutCommit()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->fileStorageService
            ->expects(self::once())
            ->method('load')
            ->willThrowException(FileException::error('test'));

        $configData = $this->createMock(ConfigDataInterface::class);

        $this->fileStorageService
            ->expects(self::exactly(1))
            ->method('save')
            ->with(self::isArray(), 'config');

        $this->fileCacheService
            ->expects(self::exactly(1))
            ->method('save')
            ->with(self::anything());

        $configFile = new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );

        $configData->expects(self::once())
                   ->method('setConfigDate');
        $configData->expects(self::once())
                   ->method('setConfigSaver');
        $configData->expects(self::once())
                   ->method('setConfigHash');
        $configData->expects(self::never())
                   ->method('getAttributes');

        $configFile->save($configData, false);

        $this->assertEquals($configData, $configFile->getConfigData());
    }

    /**
     * @throws ConfigException
     * @throws Exception
     * @throws FileException
     * @throws EnvironmentIsBrokenException
     */
    public function testGenerateUpgradeKey()
    {
        $this->fileCacheService
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->fileStorageService
            ->expects(self::once())
            ->method('load')
            ->willThrowException(FileException::error('test'));

        $this->fileStorageService
            ->expects(self::exactly(2))
            ->method('save')
            ->with(self::isArray(), 'config');

        $this->fileCacheService
            ->expects(self::exactly(2))
            ->method('save')
            ->with(self::anything());

        $configFile = new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );

        $configFile->generateUpgradeKey();

        $this->assertNotEmpty($configFile->getConfigData()->getUpgradeKey());
    }

    /**
     * @throws ConfigException
     * @throws Exception
     * @throws FileException
     * @throws EnvironmentIsBrokenException
     */
    public function testGenerateUpgradeKeyWithExistingKey()
    {
        $configData = $this->createMock(ConfigDataInterface::class);
        $configData->expects(self::once())
                   ->method('getUpgradeKey')
                   ->willReturn(self::$faker->sha1());

        $configData->expects(self::never())
                   ->method('setUpgradeKey');

        $configFile = new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context,
            $configData
        );

        $configFile->generateUpgradeKey();
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    public function testReload()
    {
        $this->fileCacheService
            ->expects(self::exactly(2))
            ->method('exists')
            ->willReturn(false);

        $this->fileStorageService
            ->expects(self::never())
            ->method('getFileTime');

        $attributes = [
            ConfigDataInterface::CONFIG_VERSION => self::$faker->colorName(),
            ConfigDataInterface::INSTALLED => self::$faker->boolean(),
            ConfigDataInterface::CONFIG_DATE => self::$faker->unixTime()
        ];

        $configData = new ConfigData($attributes);

        $this->fileStorageService
            ->expects(self::exactly(2))
            ->method('load')
            ->with('config')
            ->willReturn($attributes);

        $this->fileCacheService
            ->expects(self::exactly(2))
            ->method('save')
            ->with($configData);

        $configFile = new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        );

        $configFile->reload();
    }

    /**
     * @throws Exception
     * @throws ContextException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fileStorageService = $this->createMock(XmlFileStorageService::class);
        $this->fileCacheService = $this->createMock(FileCacheService::class);
    }

    /**
     * Every setting in `config.xml` can actually reach `ConfigData`.
     *
     * `configMapper()` walks `ConfigData`'s setters and, for each one whose parameter is a *named
     * builtin*, coerces the file's value to that type. Anything else — a union type, a class, an
     * intersection — falls through the `instanceof ReflectionNamedType && isBuiltin()` test and is
     * **skipped in silence**: the setting stays at its default, nothing is logged, and nothing
     * fails. A configuration option that quietly never loads is close to unfindable from the
     * outside, so the contract is asserted here rather than left to be discovered.
     */
    #[Test]
    public function everyConfigSetterTakesATypeTheMapperCanRead(): void
    {
        $unreadable = [];

        foreach ((new ReflectionClass(ConfigData::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'set')) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
                    $unreadable[] = sprintf('%s(%s)', $method->getName(), (string)$type);
                }
            }
        }

        self::assertSame(
            [],
            $unreadable,
            'these settings would be skipped by ConfigFile::configMapper() and never load from config.xml'
        );
    }

    /**
     * An empty element in the file reaches the getters as the type they promise.
     *
     * `config.xml` writes an unset boolean as `<demoEnabled></demoEnabled>`, and the loader answers
     * that as the empty string — the key *exists*, so `DataCollection::get()` returns `''` rather
     * than the default the getter hands it. `ConfigData::isDemoEnabled()` is declared `: bool`, and
     * given `''` directly it does throw:
     * "Return value must be of type bool, string returned". The load path does not, and this says
     * so end to end.
     *
     * Deliberately not claimed as a test of `configMapper()`'s `(bool)` cast: removing that cast
     * leaves this passing, because the `?bool` setter coerces the empty string on its own. Two
     * mechanisms hold this up and the test does not distinguish them — what it pins is the outcome,
     * which is the part that matters and the part a direct `new ConfigData(['demoEnabled' => ''])`
     * does not get.
     *
     * @throws ConfigException
     * @throws Exception
     */
    #[Test]
    public function anEmptyElementBecomesTheTypeTheGetterPromises(): void
    {
        $this->fileCacheService->method('exists')->willReturn(false);
        $this->fileStorageService->method('getFileTime')->willReturn(time());
        $this->fileStorageService
            ->method('load')
            ->willReturn(
                [
                    // exactly what XmlFileStorage answers for empty elements
                    ConfigDataInterface::DEMO_ENABLED => '',
                    ConfigDataInterface::MAINTENANCE => '',
                    ConfigDataInterface::SESSION_TIMEOUT => '',
                    ConfigDataInterface::CONFIG_HASH => 'a-hash',
                ]
            );

        $configData = (new ConfigFile(
            $this->fileStorageService,
            $this->fileCacheService,
            $this->context
        ))->getConfigData();

        self::assertFalse($configData->isDemoEnabled());
        self::assertFalse($configData->isMaintenance());
        self::assertSame(0, $configData->getSessionTimeout());
    }

}
