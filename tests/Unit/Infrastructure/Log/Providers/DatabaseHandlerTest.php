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

namespace SP\Tests\Unit\Infrastructure\Log\Providers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\LanguageInterface;
use SP\Infrastructure\Log\Providers\DatabaseHandler;
use SP\Domain\Security\Models\Eventlog;
use SP\Application\Security\Ports\EventlogService;
use SP\Tests\Support\Generators\ConfigDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class DatabaseHandlerTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DatabaseHandlerTest extends UnitaryTestCase
{
    private const A_SECRET = 'SuperSecretMasterPassword123';

    private MockObject|EventlogService   $eventLogService;
    private MockObject|LanguageInterface $language;
    private DatabaseHandler              $databaseHandler;
    private ConfigData                   $configData;

    public function testGetEventsString()
    {
        $expected = 'test_a\.|test_b\.|upgrade\.|acl\.deny|plugin\.load\.error|show\.authToken|clear\.eventlog|clear\.track|refresh\.masterPassword|update\.masterPassword\.start|update\.masterPassword\.end|request\.account|edit\.user\.password|save\.config\.|create\.tempMasterPassword|run\.import\.start|run\.import\.end';
        $out = $this->databaseHandler->getEvents();

        $this->assertEquals($expected, $out);
    }

    public function testGetEventsStringWithNoConfiguredEvents()
    {
        $expected = 'upgrade\.|acl\.deny|plugin\.load\.error|show\.authToken|clear\.eventlog|clear\.track|refresh\.masterPassword|update\.masterPassword\.start|update\.masterPassword\.end|request\.account|edit\.user\.password|save\.config\.|create\.tempMasterPassword|run\.import\.start|run\.import\.end';

        $this->configData->setLogEvents([]);

        $databaseHandler = new DatabaseHandler($this->application, $this->eventLogService, $this->language);
        $out = $databaseHandler->getEvents();

        $this->assertEquals($expected, $out);
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdate()
    {
        $eventMessage = EventMessage::build()->addDescription('a_description')->addDetail('a_detail', 'a_value');
        $event = new Event('test_a.update', $this, $eventMessage);

        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->eventLogService
            ->expects($this->once())
            ->method('create')
            ->with(
                self::callback(static function (Eventlog $eventlog) use ($eventMessage) {
                    return $eventlog->getDescription() === $eventMessage->composeText()
                           && $eventlog->getAction() === 'test_a.update'
                           && $eventlog->getLevel() == 'INFO';
                })
            );

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $this->databaseHandler->update($event);
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdateWithNoEventMessage()
    {
        $event = new Event('test_a.update', $this);

        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->eventLogService
            ->expects($this->once())
            ->method('create')
            ->with(
                self::callback(static function (Eventlog $eventlog) {
                    return $eventlog->getDescription() === null
                           && $eventlog->getAction() === 'test_a.update'
                           && $eventlog->getLevel() == 'INFO';
                })
            );

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $this->databaseHandler->update($event);
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdateWithSPExceptionMessage()
    {
        $event = new Event('test_a.update', SPException::error('an_exception', 'a_hint'));

        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->eventLogService
            ->expects($this->once())
            ->method('create')
            ->with(
                self::callback(static function (Eventlog $eventlog) {
                    return $eventlog->getDescription() ===
                           'SP\Domain\Core\Exceptions\SPException: [0]: an_exception (a_hint)'
                           && $eventlog->getAction() === 'test_a.update'
                           && $eventlog->getLevel() == 'ERROR';
                })
            );

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $this->databaseHandler->update($event);
    }

    /**
     * A logged exception records what went wrong, and none of the values that were on the stack.
     *
     * The row used to be `(string)$source`, and `Exception::__toString()` embeds
     * `getTraceAsString()`, which prints each frame's **argument values** — the first 15 characters
     * of every string. The chains that throw into this sink include the crypt and database layers
     * and the LDAP providers, so a master password, an account password or a bind credential can be
     * an argument on the way to the throw point; the row is readable by anyone whose profile has
     * `isEvl()`, and the event log can be searched and exported.
     *
     * `formatStackTrace()` is the same trace with every argument reduced to its type, and
     * `processException()` has always used it for exactly this reason.
     *
     * Whether a trace carries arguments at all is an ini setting that differs between a development
     * build and a production one, so it is pinned here rather than assumed — `FunctionsTest` does
     * the same, and without it this passes locally and proves nothing wherever the production ini
     * is in force.
     */
    public function testALoggedExceptionCarriesNoArgumentValues()
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        $description = null;

        $this->eventLogService
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(
                static function (Eventlog $eventlog) use (&$description): int {
                    $description = $eventlog->getDescription();

                    return 1;
                }
            );

        try {
            $throw = static function (string $masterPassword, string $accountKey): void {
                throw new RuntimeException('could not decrypt');
            };

            try {
                $throw(self::A_SECRET, 'an-account-key');
            } catch (RuntimeException $e) {
                $this->databaseHandler->update(new Event('test_a.update', $e));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
        }

        self::assertIsString($description);
        self::assertStringNotContainsString(substr(self::A_SECRET, 0, 15), $description);
        self::assertStringNotContainsString('an-account-key', $description);

        // ...and it is still an account of what happened, or withholding the arguments would have
        // been achieved just as well by logging nothing.
        self::assertStringContainsString('could not decrypt', $description);
        self::assertStringContainsString('String', $description, 'arguments are recorded by type');
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdateWithException()
    {
        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->eventLogService
            ->expects($this->once())
            ->method('create')
            ->willThrowException(new RuntimeException('test'));

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $this->databaseHandler->update(new Event('test_a.update', $this));
    }

    protected function buildConfig(): ConfigFileService
    {
        $this->configData = ConfigDataGenerator::factory()->buildConfigData();
        $this->configData->setLogEvents(['test_a.', 'test_b.']);

        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($this->configData);

        return $config;
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->eventLogService = $this->createMock(EventlogService::class);
        $this->language = $this->createMock(LanguageInterface::class);

        $this->databaseHandler = new DatabaseHandler($this->application, $this->eventLogService, $this->language);
    }
}
