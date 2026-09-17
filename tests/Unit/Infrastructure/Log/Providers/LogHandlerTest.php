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
use Psr\Log\LoggerInterface;
use RuntimeException;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Log\Providers\LogHandler;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class LogHandlerTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LogHandlerTest extends UnitaryTestCase
{

    private LoggerInterface|MockObject   $logger;
    private MockObject|LanguageInterface $language;
    private RequestService|MockObject    $request;
    private LogHandler                   $logHandler;

    /**
     * @throws InvalidClassException
     */
    public function testUpdate()
    {
        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $ipv4 = self::$faker->ipv4();

        $this->request
            ->expects($this->once())
            ->method('getClientAddress')
            ->with(true)
            ->willReturn($ipv4);

        $eventMessage = EventMessage::build()->addDescription('test');
        $event = new Event('test.event', $this, $eventMessage);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'test.event',
                self::callback(function (array $event) use ($eventMessage, $ipv4) {
                    return $event['message'] === $eventMessage->composeText(' | ')
                           && $event['address'] === $ipv4
                           && $event['user'] === $this->context->getUserData()->login
                           && !empty($event['caller']);
                })
            );

        $this->logHandler->update($event);
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdateWithNoMessage()
    {
        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $ipv4 = self::$faker->ipv4();

        $this->request
            ->expects($this->once())
            ->method('getClientAddress')
            ->with(true)
            ->willReturn($ipv4);

        $event = new Event('test.event', $this);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'test.event',
                self::callback(function (array $event) use ($ipv4) {
                    return $event['message'] === 'N/A'
                           && $event['address'] === $ipv4
                           && $event['user'] === $this->context->getUserData()->login
                           && !empty($event['caller']);
                })
            );

        $this->logHandler->update($event);
    }

    /**
     * A log that cannot be written does not fail the thing it was reporting on.
     *
     * This receiver is attached on every request — before the install check, and regardless of any
     * config flag — and it was the only one of the four with no guard around `update()`:
     * `DatabaseHandler`, `MailEvent` and `NotificationEvent` all catch and hand to
     * `processException()`. Monolog's `StreamHandler` throws when `var/syspass.log` cannot be
     * opened or appended to, and `notify()` is always called *after* the work it describes, so a
     * full disk turned a completed operation into an error response — the administrator is told a
     * master-password rotation failed when it had already finished.
     */
    public function testAFailingLoggerDoesNotFailTheRequest()
    {
        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->willThrowException(new RuntimeException('could not write to var/syspass.log'));

        // And the locales are still put back, which a plain try/catch around the call would miss.
        $this->language->expects($this->once())->method('setAppLocales');
        $this->language->expects($this->once())->method('unsetAppLocales');

        $this->logHandler->update(new Event('test.event', $this));

        self::assertTrue(true, 'update() returned rather than propagating');
    }

    /**
     * @throws InvalidClassException
     */
    public function testUpdateWithExceptionSource()
    {
        $this->language
            ->expects($this->once())
            ->method('setAppLocales');

        $this->language
            ->expects($this->once())
            ->method('unsetAppLocales');

        $ipv4 = self::$faker->ipv4();

        $this->request
            ->expects($this->once())
            ->method('getClientAddress')
            ->with(true)
            ->willReturn($ipv4);

        $event = new Event('test.event', new RuntimeException('an_exception'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'test.event',
                self::callback(function (array $event) use ($ipv4) {
                    return $event['message'] === 'an_exception'
                           && $event['address'] === $ipv4
                           && $event['user'] === $this->context->getUserData()->login
                           && !empty($event['caller']);
                })
            );

        $this->logHandler->update($event);
    }


    public function testGetEvents()
    {
        $out = $this->logHandler->getEvents();

        $expected = 'upgrade\.|acl\.deny|plugin\.load\.error|show\.authToken|clear\.eventlog|clear\.track|refresh\.masterPassword|update\.masterPassword\.start|update\.masterPassword\.end|request\.account|edit\.user\.password|save\.config\.|create\.tempMasterPassword|run\.import\.start|run\.import\.end';

        $this->assertEquals($expected, $out);
    }

    public function testGetEventsWithConfigEvents()
    {
        $this->config->getConfigData()->setLogEvents(['test.event_a', 'test.event_b']);

        $logHandler = new LogHandler($this->application, $this->logger, $this->language, $this->request);

        $out = $logHandler->getEvents();

        $expected = 'test\.event_a|test\.event_b|upgrade\.|acl\.deny|plugin\.load\.error|show\.authToken|clear\.eventlog|clear\.track|refresh\.masterPassword|update\.masterPassword\.start|update\.masterPassword\.end|request\.account|edit\.user\.password|save\.config\.|create\.tempMasterPassword|run\.import\.start|run\.import\.end';

        $this->assertEquals($expected, $out);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->language = $this->createMock(LanguageInterface::class);
        $this->request = $this->createMock(RequestService::class);

        $this->logHandler = new LogHandler($this->application, $this->logger, $this->language, $this->request);
    }
}
