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

namespace SP\Tests\Unit\Domain\Plugin\Services;

use Defuse\Crypto\Exception\CryptoException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Events\Event;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\NoSuchPropertyException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Plugin\Ports\PluginCompatilityService;
use SP\Domain\Plugin\Ports\PluginLoaderService;
use SP\Domain\Plugin\Ports\PluginOperationInterface;
use SP\Domain\Plugin\Services\PluginBase;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class PluginBaseTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class PluginBaseTest extends UnitaryTestCase
{

    private PluginBase                          $pluginBase;
    private PluginOperationInterface|MockObject $pluginOperation;
    private PluginCompatilityService|MockObject $pluginCompatilityService;
    private PluginLoaderService|MockObject      $pluginLoaderService;

    public function testGetThemeDir()
    {
        $this->assertNull($this->pluginBase->getThemeDir());
    }

    public function testGetBase()
    {
        $this->assertNull($this->pluginBase->getBase());
    }

    public function testGetData()
    {
        $this->assertNull($this->pluginBase->getData());
    }

    /**
     * @throws ServiceException
     * @throws ConstraintException
     * @throws NoSuchPropertyException
     * @throws CryptoException
     * @throws QueryException
     */
    public function testSaveData()
    {
        $data = (object)['a' => 'test', 'b' => ['test']];

        $this->pluginOperation
            ->expects($this->once())
            ->method('create')
            ->with(100, $data);

        $this->pluginBase->saveData(100, $data);

        $this->assertEquals($data, $this->pluginBase->getData());
    }

    /**
     * @throws ServiceException
     * @throws ConstraintException
     * @throws NoSuchPropertyException
     * @throws CryptoException
     * @throws QueryException
     */
    public function testSaveDataWithUpdate()
    {
        $dataCreate = (object)['a' => 'test', 'b' => ['test']];

        $this->pluginOperation
            ->expects($this->once())
            ->method('create')
            ->with(100, $dataCreate);

        $this->pluginBase->saveData(100, $dataCreate);

        $dataUpdate = (object)['c' => 'test', 'd' => ['test']];

        $this->pluginOperation
            ->expects($this->once())
            ->method('update')
            ->with(100, $dataUpdate);

        $this->pluginBase->saveData(100, $dataUpdate);

        $this->assertEquals($dataUpdate, $this->pluginBase->getData());
    }

    /**
     * setLocales() is how a plugin registers its own gettext translation domain, so every string
     * it shows a user can be translated instead of always falling back to the raw (English)
     * source text. It has no concrete src/ subclass and nothing in this suite exercises it
     * otherwise, so this pins the two things that make the registration correct: the domain is
     * the plugin's name lower-cased (gettext domains are matched verbatim), and the directory is
     * the plugin's base directory plus "locales".
     *
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function testSetLocalesRegistersThePluginsTranslationDomain(): void
    {
        $pluginOperation = $this->createStub(PluginOperationInterface::class);
        $pluginCompatilityService = $this->createStub(PluginCompatilityService::class);
        $pluginCompatilityService->method('checkFor')->willReturn(false);
        $pluginLoaderService = $this->createStub(PluginLoaderService::class);

        $pluginBase = new class($pluginOperation, $pluginCompatilityService, $pluginLoaderService) extends PluginBase {

            public function update(Event $event): void
            {
            }

            public function getEvents(): ?string
            {
                return null;
            }

            public function getAuthor(): ?string
            {
                return null;
            }

            public function getVersion(): ?array
            {
                return null;
            }

            public function getCompatibleVersion(): ?array
            {
                return null;
            }

            public function getName(): ?string
            {
                return 'TestPlugin';
            }

            public function getBase(): ?string
            {
                // bindtextdomain() is a C-level call: it only keeps a binding that points at a
                // real directory on disk, so this points at one — APP_PATH in the test bootstrap is
                // a vfsStream URL, which gettext cannot read. REAL_APP_ROOT rather than a literal,
                // since the checkout lives somewhere else on CI.
                return REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'resources';
            }

            public function onLoad()
            {
            }

            public function onUpgrade(string $version)
            {
            }

            public function exposeSetLocales(): void
            {
                $this->setLocales();
            }
        };

        $pluginBase->exposeSetLocales();

        self::assertSame(
            REAL_APP_ROOT . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'locales',
            bindtextdomain('testplugin', null)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginOperation = $this->createMock(PluginOperationInterface::class);
        $this->pluginCompatilityService = $this->createMock(PluginCompatilityService::class);
        $this->pluginLoaderService = $this->createMock(PluginLoaderService::class);

        $this->pluginCompatilityService
            ->expects($this->once())
            ->method('checkFor')
            ->with(self::isInstanceOf(PluginBase::class))
            ->willReturn(true);

        $this->pluginLoaderService
            ->expects($this->once())
            ->method('loadFor')
            ->with(self::isInstanceOf(PluginBase::class));

        $this->pluginBase = new class(
            $this->pluginOperation,
            $this->pluginCompatilityService,
            $this->pluginLoaderService
        ) extends PluginBase {

            public function update(Event $event): void
            {
            }

            public function getEvents(): ?string
            {
                // TODO: Implement getEventsString() method.
            }

            public function getAuthor(): ?string
            {
                // TODO: Implement getAuthor() method.
            }

            public function getVersion(): ?array
            {
                // TODO: Implement getVersion() method.
            }

            public function getCompatibleVersion(): ?array
            {
                // TODO: Implement getCompatibleVersion() method.
            }

            public function getName(): ?string
            {
                // TODO: Implement getName() method.
            }

            public function onLoad()
            {
                // TODO: Implement onLoad() method.
            }

            public function onUpgrade(string $version)
            {
                // TODO: Implement onUpgrade() method.
            }
        };
    }
}
