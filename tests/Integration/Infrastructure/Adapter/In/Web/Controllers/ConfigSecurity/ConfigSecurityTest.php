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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigSecurity;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Events\EventReceiver;
use SP\Infrastructure\Events\EventDispatcher;
use stdClass;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the endpoint that saves the two session/transport hardening switches: forcing HTTPS and
 * encrypting the session data at rest. Unlike its neighbours (auth, wiki, DokuWiki...) this
 * controller has no validation branches of its own — it is two plain checkboxes — so what is
 * worth pinning down is that a submission always sets both flags to exactly what the form says,
 * including forcing them off when the form leaves them out, and that a demo instance still
 * refuses to persist either change.
 */
#[Group('integration')]
class ConfigSecurityTest extends IntegrationTestCase
{
    /**
     * Both switches on is persisted as-is, and the save is announced on the event bus under its
     * own name (with no message attached — this endpoint never explains itself the way auth does).
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function savingBothFlagsOnPersistsThem(): void
    {
        $configData = $this->freshConfigData();
        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->runController(
            ['https_enabled' => 'on', 'encrypt_session_enabled' => 'on'],
            $configFileService,
            $eventDispatcher
        );

        self::assertSame($configData, $saved->value);
        self::assertTrue($configData->isHttpsEnabled());
        self::assertTrue($configData->isEncryptSession());

        self::assertCount(1, $notified->value);
        self::assertSame('save.config.security', $notified->value[0]->getName());
        self::assertNull($notified->value[0]->getEventMessage());
    }

    /**
     * A checkbox that is left unchecked is simply absent from the form, and the controller reads
     * that as an explicit "off" rather than "leave it as it was" — so submitting the form with
     * neither field present turns both switches off, even starting from both being on.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function submittingWithoutTheFieldsTurnsBothFlagsOff(): void
    {
        $configData = $this->freshConfigData();
        $configData->setHttpsEnabled(true);
        $configData->setEncryptSession(true);

        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        // Over HTTPS: the stored config already requires it, and Init now refuses a plaintext
        // request on such an installation instead of merely mentioning where it should have gone.
        $this->runController([], $configFileService, $eventDispatcher, ['HTTPS' => 'on']);

        self::assertSame($configData, $saved->value);
        self::assertFalse($configData->isHttpsEnabled());
        self::assertFalse($configData->isEncryptSession());
        self::assertCount(1, $notified->value);
    }

    /**
     * A demo instance refuses the save outright — the settings are never written back to the
     * configuration file and the event is never raised.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aDemoInstanceRefusesToSaveIt(): void
    {
        $configData = $this->freshConfigData();
        $configData->setDemoEnabled(true);

        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"WARNING","description":"Ey, this is a DEMO!!","data":null}');

        $this->runController(['https_enabled' => 'on'], $configFileService, $eventDispatcher);

        self::assertNull($saved->value);
        self::assertCount(0, $notified->value);
        // The setters run unconditionally before the demo check, so the in-memory object is
        // mutated on the way in — it is only the write to the configuration file, and the
        // "save" event, that the demo gate actually stops.
        self::assertTrue($configData->isHttpsEnabled());
    }

    /**
     * A freshly installed, up-to-date instance — everything the boot-time checks
     * (installation state, upgrade needed, maintenance mode) require before a request even
     * reaches the controller.
     */
    private function freshConfigData(): ConfigData
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
     * A ConfigFileService double that hands back the given (real, mutable) ConfigData and
     * records whatever is passed to save(), so a test can tell whether persistence actually
     * happened without caring how the fake file write itself is implemented.
     *
     * @return array{0: ConfigFileService, 1: stdClass}
     * @throws Exception
     */
    private function spyingConfigFileService(ConfigData $configData): array
    {
        $captured = new stdClass();
        $captured->value = null;

        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);
        $configFileService->method('save')->willReturnCallback(
            static function (ConfigDataInterface $savedConfigData) use ($captured, &$configFileService) {
                $captured->value = $savedConfigData;

                return $configFileService;
            }
        );

        return [$configFileService, $captured];
    }

    /**
     * A real EventDispatcher with a receiver attached that records every notified event, so a
     * test can inspect the event name and its message. EventDispatcher is final and
     * SimpleControllerBase declares its $eventDispatcher property with that concrete type (not
     * the interface), so a mock double cannot be assigned there — the receiver mechanism the
     * class already exposes is used instead.
     *
     * @return array{0: EventDispatcherInterface, 1: stdClass}
     */
    private function spyingEventDispatcher(): array
    {
        $captured = new stdClass();
        $captured->value = [];

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->attach(
            new class ($captured) implements EventReceiver {
                public function __construct(private readonly stdClass $captured)
                {
                }

                public function update(Event $event): void
                {
                    $this->captured->value[] = $event;
                }

                public function getEvents(): ?string
                {
                    return '*';
                }
            }
        );

        return [$eventDispatcher, $captured];
    }

    /**
     * @param array<string, string|int> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    /**
     * @param array<string, string> $server Extra server parameters — an installation that already
     *                                      has "Force HTTPS" on refuses a plaintext request in
     *                                      Init, so a test starting from that state has to arrive
     *                                      over HTTPS to reach the controller at all.
     */
    private function runController(
        array $fields,
        ConfigFileService $configFileService,
        EventDispatcherInterface $eventDispatcher,
        array $server = []
    ): void {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'configSecurity/save'],
                $fields,
                server: $server
            ),
            [
                ConfigFileService::class => $configFileService,
                EventDispatcherInterface::class => $eventDispatcher,
            ]
        );

        IntegrationTestCase::runApp($container);
    }
}
