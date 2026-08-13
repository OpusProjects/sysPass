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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigAuth;

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
use SP\Domain\Core\Messages\TextFormatter;
use SP\Infrastructure\Events\EventDispatcher;
use stdClass;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the endpoint that saves HTTP Basic / SSO authentication settings. It is the switch
 * that lets people log in without sysPass's own form (relying on a header the web server put
 * there instead), so what it persists — and what it silently leaves alone — matters: a stale
 * SSO default group or profile left over from a previous configuration would only ever bite once
 * Basic auth is turned back on, which the controller's own asymmetry (see below) means is easy to
 * miss.
 */
#[Group('integration')]
class ConfigAuthTest extends IntegrationTestCase
{
    /**
     * Enabling Basic auth for the first time persists every field the form carries, and the
     * transition from off to on is the one case that is worth telling anyone about.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function enablingAuthBasicSavesTheAutologinDomainAndSsoDefaults(): void
    {
        $configData = $this->freshConfigData();
        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->runController(
            [
                'authbasic_enabled' => 'on',
                'authbasicautologin_enabled' => 'on',
                'authbasic_domain' => 'sso.example.com',
                'sso_defaultgroup' => 5,
                'sso_defaultprofile' => 7,
            ],
            $configFileService,
            $eventDispatcher
        );

        self::assertSame($configData, $saved->value);
        self::assertTrue($configData->isAuthBasicEnabled());
        self::assertTrue($configData->isAuthBasicAutoLoginEnabled());
        self::assertSame('sso.example.com', $configData->getAuthBasicDomain());
        self::assertSame(5, $configData->getSsoDefaultGroup());
        self::assertSame(7, $configData->getSsoDefaultProfile());

        self::assertCount(1, $notified->value);
        self::assertSame('save.config.auth', $notified->value[0]->getName());
        self::assertSame(
            'Auth Basic enabled',
            $notified->value[0]->getEventMessage()->getDescription(new TextFormatter(), false)
        );
    }

    /**
     * Once Basic auth is already on, saving again (say, only to change the domain) does not repeat
     * the "enabled" notice — it is only raised on the off-to-on transition, so a second save is
     * silent even though it does update the stored fields.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function reSavingWhileAlreadyEnabledUpdatesTheFieldsWithoutANotice(): void
    {
        $configData = $this->freshConfigData();
        $configData->setAuthBasicEnabled(true);
        $configData->setAuthBasicAutoLoginEnabled(false);
        $configData->setAuthBasicDomain('old.example.com');

        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->runController(
            [
                'authbasic_enabled' => 'on',
                'authbasicautologin_enabled' => 'on',
                'authbasic_domain' => 'new.example.com',
                'sso_defaultgroup' => 3,
                'sso_defaultprofile' => 4,
            ],
            $configFileService,
            $eventDispatcher
        );

        self::assertSame($configData, $saved->value);
        self::assertTrue($configData->isAuthBasicEnabled());
        self::assertTrue($configData->isAuthBasicAutoLoginEnabled());
        self::assertSame('new.example.com', $configData->getAuthBasicDomain());

        self::assertCount(1, $notified->value);
        self::assertSame(
            '',
            $notified->value[0]->getEventMessage()->getDescription(new TextFormatter(), false)
        );
    }

    /**
     * Turning Basic auth back off clears the flags that gate it, but the domain and SSO defaults
     * are simply left as they were — they only get read while Basic auth is enabled
     * (SP\Domain\Auth\Providers\Browser\BrowserAuth, SP\Application\User\Services\User), so an
     * inert leftover value here is harmless and re-enabling later just picks them back up.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function disablingClearsTheFlagsButLeavesDomainAndSsoDefaultsUntouched(): void
    {
        $configData = $this->freshConfigData();
        $configData->setAuthBasicEnabled(true);
        $configData->setAuthBasicAutoLoginEnabled(true);
        $configData->setAuthBasicDomain('sso.example.com');
        $configData->setSsoDefaultGroup(11);
        $configData->setSsoDefaultProfile(22);

        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        // No authbasic_enabled field at all — exactly what an unchecked checkbox submits.
        $this->runController([], $configFileService, $eventDispatcher);

        self::assertSame($configData, $saved->value);
        self::assertFalse($configData->isAuthBasicEnabled());
        self::assertFalse($configData->isAuthBasicAutoLoginEnabled());
        self::assertSame('sso.example.com', $configData->getAuthBasicDomain());
        self::assertSame(11, $configData->getSsoDefaultGroup());
        self::assertSame(22, $configData->getSsoDefaultProfile());

        self::assertCount(1, $notified->value);
        self::assertSame(
            'Auth Basic disabled',
            $notified->value[0]->getEventMessage()->getDescription(new TextFormatter(), false)
        );
    }

    /**
     * Leaving the section switched off when it was already off needs no parameters at all — there
     * is nothing to validate — and the save still goes through and reports success, even though
     * nothing about auth actually changed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function leavingItDisabledNeedsNoParameters(): void
    {
        $configData = $this->freshConfigData();

        [$configFileService, $saved] = $this->spyingConfigFileService($configData);
        [$eventDispatcher, $notified] = $this->spyingEventDispatcher();

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->runController([], $configFileService, $eventDispatcher);

        self::assertSame($configData, $saved->value);
        self::assertFalse($configData->isAuthBasicEnabled());

        self::assertCount(1, $notified->value);
        self::assertSame(
            '',
            $notified->value[0]->getEventMessage()->getDescription(new TextFormatter(), false)
        );
    }

    /**
     * A demo instance refuses the save outright, before anything is written back to the
     * configuration file — the in-memory object may have been mutated on the way in, but
     * ConfigFileService::save() is never reached.
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

        $this->runController(['authbasic_enabled' => 'on'], $configFileService, $eventDispatcher);

        self::assertNull($saved->value);
        self::assertCount(0, $notified->value);
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
    private function runController(
        array $fields,
        ConfigFileService $configFileService,
        EventDispatcherInterface $eventDispatcher
    ): void {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configAuth/save'], $fields),
            [
                ConfigFileService::class => $configFileService,
                EventDispatcherInterface::class => $eventDispatcher,
            ]
        );

        IntegrationTestCase::runApp($container);
    }
}
