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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigAccount;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Events\EventReceiver;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Class ConfigAccountControllerTest
 */
#[Group('integration')]
class ConfigAccountTest extends IntegrationTestCase
{

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function save()
    {
        $data = [
            'publiclinks_enabled' => true,
            'publiclinks_image_enabled' => self::$faker->boolean(),
            'publiclinks_maxtime' => self::$faker->randomNumber(4),
            'publiclinks_maxviews' => self::$faker->randomNumber(4),
            'files_enabled' => true,
            'files_allowed_size' => self::$faker->randomNumber(3),
            'files_allowed_mimetypes' => [self::$faker->mimeType(), self::$faker->mimeType()],
            'account_globalsearch_enabled' => self::$faker->boolean(),
            'account_passtoimage_enabled' => self::$faker->boolean(),
            'account_link_enabled' => self::$faker->boolean(),
            'account_fullgroup_access_enabled' => self::$faker->boolean(),
            'account_count' => self::$faker->randomNumber(3),
            'account_resultsascards_enabled' => self::$faker->boolean(),
            'account_expire_enabled' => self::$faker->boolean(),
            'account_expire_time' => self::$faker->randomNumber(8),
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configAccount/save'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * account_expire_time is in days and gets multiplied up to seconds, so an unbounded value
     * overflowed int arithmetic into a float that setAccountExpireTime(?int) refused.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[TestWith(['99999999999999999999'])]
    #[TestWith(['-1'])]
    #[TestWith([''])]
    public function saveWithOutOfRangeExpireTime(string $expireTime)
    {
        $data = [
            'account_expire_enabled' => true,
            'account_expire_time' => $expireTime,
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configAccount/save'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveWithoutExpireTime()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configAccount/save'], [])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');
    }

    /**
     * A file size over the 16MB cap is refused before anything is persisted — the request
     * never reaches saveConfig(), so the previously stored settings are left untouched.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function savingWithAnOversizedFileLimitIsRejected()
    {
        $configData = $this->newConfigData();
        $configData->setFilesEnabled(false);
        $configData->setFilesAllowedSize(1024);

        $container = $this->buildConfigAccountContainer(
            $configData,
            ['files_enabled' => true, 'files_allowed_size' => 16385]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Maximum size per file is 16MB","data":null}'
        );

        self::assertFalse($configData->isFilesEnabled(), 'the rejected save must not enable files');
        self::assertSame(1024, $configData->getFilesAllowedSize(), 'the previous limit must be left alone');
    }

    /**
     * Submitting the form with the files toggle off, when files were previously enabled,
     * actually flips the stored flag off and records why — not just an "OK" that leaves the
     * feature silently enabled underneath.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function disablingFilesTurnsOffTheFlagAndRecordsWhy()
    {
        $configData = $this->newConfigData();
        $configData->setFilesEnabled(true);
        $configData->setFilesAllowedSize(2048);

        $container = $this->buildConfigAccountContainer($configData, []);
        $receiver = $this->attachEventRecorder($container);

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        self::assertFalse($configData->isFilesEnabled());

        $events = $this->savedConfigEvents($receiver);
        self::assertCount(1, $events);
        self::assertStringContainsString('Files disabled', (string)$events[0]->getEventMessage()?->composeText());
    }

    /**
     * The same disable-and-record behaviour applies to public links independently of files.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function disablingPublicLinksTurnsOffTheFlagAndRecordsWhy()
    {
        $configData = $this->newConfigData();
        $configData->setPublinksEnabled(true);

        $container = $this->buildConfigAccountContainer($configData, []);
        $receiver = $this->attachEventRecorder($container);

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        self::assertFalse($configData->isPublinksEnabled());

        $events = $this->savedConfigEvents($receiver);
        self::assertCount(1, $events);
        self::assertStringContainsString(
            'Public links disabled',
            (string)$events[0]->getEventMessage()?->composeText()
        );
    }

    /**
     * A `ConfigData` populated with just enough to satisfy the framework's own bootstrap
     * checks (installed, not in maintenance, a current app/database version) so the request
     * reaches the controller instead of being redirected by them.
     */
    private function newConfigData(): ConfigData
    {
        $configData = new ConfigData();
        $configData->setInstalled(true);
        $configData->setMaintenance(false);
        $configData->setDbName(self::$faker->colorName());
        $configData->setPasswordSalt($this->passwordSalt);
        $configData->setAppVersion(Version::getVersionStringNormalized());
        $configData->setDatabaseVersion(Version::getVersionStringNormalized());

        return $configData;
    }

    /**
     * Wires the given, real `ConfigData` instance as the one the controller reads and writes
     * back into, so a test can assert on it directly after the request runs.
     *
     * @param array<string, mixed> $postFields
     *
     * @throws Exception
     */
    private function buildConfigAccountContainer(ConfigDataInterface $configData, array $postFields): ContainerInterface
    {
        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        return $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configAccount/save'], $postFields),
            [ConfigFileService::class => $configFileService]
        );
    }

    /**
     * Attaches a recorder to the container's real event dispatcher (it is injected as its
     * concrete, final class, so it cannot be doubled) so a test can check what the controller
     * notified.
     */
    private function attachEventRecorder(ContainerInterface $container): EventReceiver
    {
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $receiver = new class implements EventReceiver {
            /** @var Event[] */
            public array $events = [];

            public function update(Event $event): void
            {
                $this->events[] = $event;
            }

            public function getEvents(): ?string
            {
                return '*';
            }
        };

        $eventDispatcher->attach($receiver);

        return $receiver;
    }

    /**
     * @param EventReceiver&object{events: Event[]} $receiver
     *
     * @return Event[]
     */
    private function savedConfigEvents(EventReceiver $receiver): array
    {
        return array_values(
            array_filter(
                $receiver->events,
                static fn(Event $event): bool => $event->getName() === 'save.config.account'
            )
        );
    }
}
