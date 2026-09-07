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

namespace SP\Tests\Unit\Domain\Upgrade\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Log\Ports\FileHandlerProvider;
use SP\Domain\Upgrade\Ports\UpgradeHandlerService;
use SP\Domain\Upgrade\Services\Upgrade;
use SP\Domain\Upgrade\Services\UpgradeException;
use SP\Domain\Core\Exceptions\FileException;
use SP\Tests\Support\Generators\ConfigDataGenerator;
use SP\Tests\Support\Stubs\UpgradeHandlerStub;
use SP\Tests\Support\UnitaryTestCase;
use stdClass;

/**
 * Class UpgradeTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class UpgradeTest extends UnitaryTestCase
{

    private MockObject|ContainerInterface $container;
    private Upgrade                       $upgrade;

    /**
     * @throws ServiceException
     * @throws InvalidClassException
     * @throws Exception
     */
    public function testRegisterUpgradeHandler()
    {
        $handler = $this->createStub(UpgradeHandlerService::class);

        $this->upgrade->registerUpgradeHandler($handler::class);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws ServiceException
     * @throws InvalidClassException
     */
    public function testRegisterUpgradeHandlerWithClassException()
    {
        $this->expectException(InvalidClassException::class);
        $this->expectExceptionMessage('Class does not either exist or implement UpgradeService class');

        $this->upgrade->registerUpgradeHandler(stdClass::class);
    }

    /**
     * @throws ServiceException
     * @throws InvalidClassException
     * @throws Exception
     */
    public function testRegisterUpgradeHandlerWithDuplicate()
    {
        $handler = $this->createStub(UpgradeHandlerService::class);

        $this->upgrade->registerUpgradeHandler($handler::class);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Class already registered');

        $this->upgrade->registerUpgradeHandler($handler::class);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws UpgradeException
     */
    public function testUpgrade()
    {
        $configData = $this->createMock(ConfigDataInterface::class);

        // Even with nothing to apply, the run records that the application is now at this
        // version. Without it ModuleBase::checkUpgradeNeeded() keeps answering yes and the
        // instance is sent back to the upgrade page on the next request.
        $configData->expects($this->once())->method('setAppVersion')->with(Version::getVersionStringNormalized());
        $this->config->expects($this->once())
                     ->method('save');

        $this->upgrade->upgrade('400.00000000', $configData);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws UpgradeException
     * @throws InvalidClassException
     */
    public function testUpgradeWithHandler()
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $handler = $this->createMock(UpgradeHandlerService::class);
        $handler->expects($this->exactly(2))
                ->method('apply')
                ->with(
                    self::callback(
                        static fn(string $version) => in_array($version, ['400.00000002', '400.00000001'], true)
                    ),
                    $configData
                )
                ->willReturn(true);

        $this->container
            ->expects($this->exactly(2))
            ->method('get')
            ->with(UpgradeHandlerStub::class)
            ->willReturn($handler);

        // Two saves for the two handlers, and a third for the version the run finished at.
        $this->config->expects($this->exactly(3))
                     ->method('save')
            ->with($configData, true);

        $this->upgrade->registerUpgradeHandler(UpgradeHandlerStub::class);
        $this->upgrade->upgrade('400.00000000', $configData);
    }

    /**
     * The resume point moves as each version finishes, not once at the end.
     *
     * What still needs running is derived from `appVersion`, and it used to be written only after
     * every handler had succeeded — while progress was really being stamped per file in
     * `databaseVersion`. An interruption between two versions therefore left a database already
     * migrated and a resume point that had not moved, and the retry re-ran a migration that had
     * already been applied: `40024210101.sql` drops a column that is no longer there and fails for
     * good, and `UpgradeConfigText` would decode text that is already decoded, which its own
     * header says must happen exactly once.
     *
     * The second version failing is what makes this say anything: the first has completed, so its
     * version must be on record before the failure, and the run must not go on to claim the
     * application is fully upgraded.
     *
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws InvalidClassException
     */
    public function testAnInterruptedUpgradeRecordsTheVersionsThatFinished()
    {
        $configData = $this->createMock(ConfigDataInterface::class);

        $handler = $this->createMock(UpgradeHandlerService::class);
        $handler->method('apply')->willReturnCallback(
            static fn(string $version): bool => $version === '400.00000001'
        );

        $this->container->method('get')->willReturn($handler);

        // The version that finished, and nothing else — in particular not the application version,
        // which would tell the next run there is nothing left to do.
        $configData->expects($this->once())->method('setAppVersion')->with('400.00000001');
        $this->config->expects($this->once())->method('save');

        $this->upgrade->registerUpgradeHandler(UpgradeHandlerStub::class);

        $this->expectException(UpgradeException::class);

        $this->upgrade->upgrade('400.00000000', $configData);
    }

    /**
     * And two versions that both finish are recorded in order, oldest first, before the run stamps
     * the application version it reached.
     *
     * The order is not incidental: the value written after each version is a resume point, so
     * applying a lower version after a higher one would move it backwards. It used to be whatever
     * order the handlers were registered and their attributes declared in.
     *
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws InvalidClassException
     */
    public function testTheVersionsAreAppliedOldestFirst()
    {
        $configData = $this->createStub(ConfigDataInterface::class);

        $applied = [];
        $handler = $this->createMock(UpgradeHandlerService::class);
        $handler->method('apply')->willReturnCallback(
            static function (string $version) use (&$applied): bool {
                $applied[] = $version;

                return true;
            }
        );

        $this->container->method('get')->willReturn($handler);

        $this->upgrade->registerUpgradeHandler(UpgradeHandlerStub::class);
        $this->upgrade->upgrade('400.00000000', $configData);

        self::assertSame(['400.00000001', '400.00000002'], $applied);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws UpgradeException
     * @throws InvalidClassException
     */
    public function testUpgradeWithHandlerWithFailedApply()
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $handler = $this->createMock(UpgradeHandlerService::class);
        // The oldest version outstanding, not whichever the stub happens to declare first: the
        // handlers now run in ascending order, because the resume point written after each one
        // must not go backwards.
        $handler->expects($this->once())
                ->method('apply')
                ->with('400.00000001', $configData)
                ->willReturn(false);

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with(UpgradeHandlerStub::class)
            ->willReturn($handler);

        $this->config->expects($this->never())
                     ->method('save');

        $this->upgrade->registerUpgradeHandler(UpgradeHandlerStub::class);

        $this->expectException(UpgradeException::class);
        $this->expectExceptionMessage('Error while applying the update');

        $this->upgrade->upgrade('400.00000000', $configData);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     * @throws FileException
     * @throws UpgradeException
     * @throws InvalidClassException
     */
    public function testUpgradeWithException()
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $handler = $this->createMock(UpgradeHandlerService::class);
        $handler->expects($this->never())
                ->method('apply');

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with(UpgradeHandlerStub::class)
            ->willThrowException(new RuntimeException('test'));

        $this->config->expects($this->never())
                     ->method('save');

        $this->upgrade->registerUpgradeHandler(UpgradeHandlerStub::class);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('test');

        $this->upgrade->upgrade('400.00000000', $configData);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $fileHandlerProvider = $this->createStub(FileHandlerProvider::class);
        $this->container = $this->createMock(ContainerInterface::class);

        $this->upgrade = new Upgrade($this->application, $fileHandlerProvider, $this->container);
    }

    /**
     * This test verifies interactions on the config (->expects() on save()), so it needs
     * a mock rather than the base class's default stub.
     *
     * @throws Exception
     */
    protected function buildConfig(): ConfigFileService
    {
        $config = $this->createMock(ConfigFileService::class);
        $config->method('getConfigData')->willReturn(ConfigDataGenerator::factory()->buildConfigData());

        return $config;
    }
}
