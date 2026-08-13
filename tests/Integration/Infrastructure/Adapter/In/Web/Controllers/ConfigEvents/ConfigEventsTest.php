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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigEvents;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * This is the screen that decides what an installation records about itself: which events go
 * into the application log, and whether they are also fanned out to a local or a remote syslog
 * daemon. None of it was under test before, so a silently dropped or silently ignored field here
 * would mean a site believes it is auditing itself when it is not, and nobody would notice until
 * the log was needed and turned out to be empty or missing entries.
 *
 * Every test drives the real save controller and inspects the exact values it hands to the
 * configuration object, rather than only checking the JSON envelope, because the envelope is
 * identical ("Configuration updated") whether the underlying settings were stored faithfully or
 * mangled on the way in.
 */
#[Group('integration')]
class ConfigEventsTest extends IntegrationTestCase
{
    /**
     * The plain, everything-on case: logging, the local event list, plain syslog and remote
     * syslog (with a host and a port) are all switched on together and must all land unchanged.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveStoresLoggingAndSyslogSettingsOnTheHappyPath(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(
            [
                'log_enabled' => 'on',
                'log_events' => ['account.create', 'account.delete'],
                'syslog_enabled' => 'on',
                'remotesyslog_enabled' => 'on',
                'remotesyslog_server' => 'syslog.example.com',
                'remotesyslog_port' => 514,
            ],
            $configData
        );

        self::assertSame(
            [
                'log_enabled' => true,
                'log_events' => ['account.create', 'account.delete'],
                'syslog_enabled' => true,
                'syslog_remote_enabled' => true,
                'syslog_server' => 'syslog.example.com',
                'syslog_port' => 514,
            ],
            $captured
        );
    }

    /**
     * The event list is free text turned into an array by the browser's multi-select, so nothing
     * stops a malformed value reaching the request. Only strings that look like a dotted event
     * identifier are kept; a name starting with a digit or containing anything other than
     * letters and dots is quietly dropped rather than stored as an unusable log filter.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveDropsEventNamesThatDoNotLookLikeEventIdentifiers(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(
            [
                'log_enabled' => 'on',
                'log_events' => ['123bad', 'account_create', 'account.create'],
            ],
            $configData
        );

        self::assertSame(['account.create'], array_values($captured['log_events']));
    }

    /**
     * The "Events" multi-select is only hidden with CSS when logging is switched off, not
     * disabled or cleared, so a browser still submits whatever was previously selected in it.
     * The controller stores that submitted list unconditionally, regardless of the logging
     * switch: turning logging off does not wipe the configured event list, it only stops it
     * being written to. Re-enabling logging later silently restores the same events.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveKeepsWhateverEventListWasSubmittedEvenWhenLoggingIsOff(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(
            [
                // log_enabled intentionally omitted: an unchecked checkbox is not submitted.
                'log_events' => ['account.create', 'account.delete'],
            ],
            $configData
        );

        self::assertFalse($captured['log_enabled']);
        self::assertSame(['account.create', 'account.delete'], $captured['log_events']);
    }

    /**
     * When the request carries no event selection at all the stored list becomes empty, rather
     * than being left untouched — the field is always written, never merely defaulted.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveStoresEmptyEventListWhenNoneAreSubmitted(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(['log_enabled' => 'on'], $configData);

        self::assertSame([], $captured['log_events']);
    }

    /**
     * Remote syslog cannot work without somewhere to send the events, so turning it on without a
     * host or a port is refused rather than silently saved as a broken configuration.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[BodyChecker('assertRefusedForMissingRemoteSyslogParameters')]
    public function saveRefusesRemoteSyslogWithoutHostOrPort(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->whenSaving(['remotesyslog_enabled' => 'on'], $configData);
    }

    /**
     * A host with no port is just as unusable as neither: both are required together.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[BodyChecker('assertRefusedForMissingRemoteSyslogParameters')]
    public function saveRefusesRemoteSyslogWithHostButNoPort(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->whenSaving(
            [
                'remotesyslog_enabled' => 'on',
                'remotesyslog_server' => 'syslog.example.com',
            ],
            $configData
        );
    }

    /**
     * A port with no host is equally unusable, and refused for the same reason.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[BodyChecker('assertRefusedForMissingRemoteSyslogParameters')]
    public function saveRefusesRemoteSyslogWithPortButNoHost(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->whenSaving(
            [
                'remotesyslog_enabled' => 'on',
                'remotesyslog_port' => 514,
            ],
            $configData
        );
    }

    /**
     * A port of zero is not a usable UDP/TCP destination port either, and the check treats it the
     * same as a missing one rather than accepting it and producing a syslog target nothing can
     * reach.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[BodyChecker('assertRefusedForMissingRemoteSyslogParameters')]
    public function saveRefusesRemoteSyslogWhenPortIsZero(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->whenSaving(
            [
                'remotesyslog_enabled' => 'on',
                'remotesyslog_server' => 'syslog.example.com',
                'remotesyslog_port' => 0,
            ],
            $configData
        );
    }

    /**
     * The local "Enable Syslog" switch and "Enable Remote Syslog" are independent controls, not a
     * master switch gating a sub-feature: remote syslog can be turned on and configured even while
     * the local syslog switch stays off, so an installation can ship events off-box without also
     * writing them to the local syslog.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveEnablesRemoteSyslogRegardlessOfTheLocalSyslogSwitch(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(
            [
                // syslog_enabled intentionally omitted, so the local switch stays off.
                'remotesyslog_enabled' => 'on',
                'remotesyslog_server' => 'syslog.example.com',
                'remotesyslog_port' => 514,
            ],
            $configData
        );

        self::assertFalse($captured['syslog_enabled']);
        self::assertTrue($captured['syslog_remote_enabled']);
        self::assertSame('syslog.example.com', $captured['syslog_server']);
        self::assertSame(514, $captured['syslog_port']);
    }

    /**
     * Switching remote syslog off again only flips the enabled flag; the previously configured
     * host and port are left in place rather than being cleared, so re-enabling it later does not
     * require re-entering them.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveDisablesRemoteSyslogButKeepsThePreviousHostAndPort(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured, syslogRemoteEnabledInitially: true);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving(
            [
                // remotesyslog_enabled intentionally omitted: the checkbox was unchecked.
            ],
            $configData
        );

        self::assertFalse($captured['syslog_remote_enabled']);
        self::assertArrayNotHasKey('syslog_server', $captured);
        self::assertArrayNotHasKey('syslog_port', $captured);
    }

    /**
     * When remote syslog was already off, leaving the checkbox unticked is a genuine no-op: the
     * controller must not touch the remote syslog fields at all rather than re-writing them to
     * the same values on every save.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveLeavesRemoteSyslogSettingsUntouchedWhenNeverEnabledAndStillOff(): void
    {
        $captured = [];
        $configData = $this->buildConfigDataStub($captured, syslogRemoteEnabledInitially: false);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        $this->whenSaving([], $configData);

        self::assertArrayNotHasKey('syslog_remote_enabled', $captured);
        self::assertArrayNotHasKey('syslog_server', $captured);
        self::assertArrayNotHasKey('syslog_port', $captured);
    }

    /**
     * The remote syslog validation is raised as a validation exception, which carries its own
     * trace as response data; only the status and the message are part of the contract tested
     * here.
     */
    private function assertRefusedForMissingRemoteSyslogParameters(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertEquals('Missing remote syslog parameters', $json->description);
    }

    /**
     * Builds a configuration double that records every value the controller tries to store,
     * keyed by field, so a test can assert on exactly what was persisted rather than only on the
     * response envelope. `isSyslogRemoteEnabled` is the one pre-existing value the controller
     * reads back (to decide whether disabling remote syslog is a real change), so it is the only
     * one a caller can seed.
     *
     * @param array<string, mixed> $captured Populated by reference as setters are invoked.
     *
     * @throws Exception
     */
    private function buildConfigDataStub(
        array &$captured,
        bool  $syslogRemoteEnabledInitially = false
    ): ConfigDataInterface {
        $configData = self::createConfiguredStub(
            ConfigDataInterface::class,
            array_merge($this->getConfigData(), ['isSyslogRemoteEnabled' => $syslogRemoteEnabledInitially])
        );

        $configData->method('setLogEnabled')->willReturnCallback(
            function (?bool $value) use (&$captured, $configData) {
                $captured['log_enabled'] = $value;

                return $configData;
            }
        );
        $configData->method('setLogEvents')->willReturnCallback(
            function (?array $value) use (&$captured, $configData) {
                $captured['log_events'] = $value;

                return $configData;
            }
        );
        $configData->method('setSyslogEnabled')->willReturnCallback(
            function (?bool $value) use (&$captured, $configData) {
                $captured['syslog_enabled'] = $value;

                return $configData;
            }
        );
        $configData->method('setSyslogRemoteEnabled')->willReturnCallback(
            function (?bool $value) use (&$captured, $configData) {
                $captured['syslog_remote_enabled'] = $value;

                return $configData;
            }
        );
        $configData->method('setSyslogServer')->willReturnCallback(
            function (?string $value) use (&$captured, $configData) {
                $captured['syslog_server'] = $value;

                return $configData;
            }
        );
        $configData->method('setSyslogPort')->willReturnCallback(
            function (?int $value) use (&$captured, $configData) {
                $captured['syslog_port'] = $value;

                return $configData;
            }
        );

        return $configData;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    private function whenSaving(array $fields, ConfigDataInterface $configData): void
    {
        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configEvents/save'], $fields),
            [ConfigFileService::class => $configFileService]
        );

        IntegrationTestCase::runApp($container);
    }
}
