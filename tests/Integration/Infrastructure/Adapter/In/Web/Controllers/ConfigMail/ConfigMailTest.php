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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigMail;

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
 * Class ConfigMailTest
 */
#[Group('integration')]
class ConfigMailTest extends IntegrationTestCase
{

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function check()
    {
        $data = [
            'mail_enabled' => true,
            'mail_server' => self::$faker->domainName(),
            'mail_port' => self::$faker->randomNumber(3),
            'mail_user' => self::$faker->userName(),
            'mail_pass' => self::$faker->password(),
            'mail_security' => 'tls',
            'mail_from' => self::$faker->email(),
            'mail_auth_enabled' => self::$faker->boolean(),
            'mail_recipients' => self::$faker->email()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/check'], $data),
        );

        $this->expectOutputString('{"status":"OK","description":"Email sent","data":["Please, check your inbox"]}');

        IntegrationTestCase::runApp($container);
    }

    /**
     * A partial form must be reported as "Missing Mail parameters", not fatal while
     * building MailParams — whose constructor takes non-nullable strings.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[TestWith([null, null, null])]
    #[TestWith(['test_server', null, null])]
    #[TestWith([null, 'me@email.com', null])]
    #[TestWith(['test_server', 'me@email.com', null])]
    public function checkWithMissingParameters(?string $mailServer, ?string $mailFrom, ?string $mailRecipients)
    {
        $data = array_filter(
            [
                'mail_enabled' => true,
                'mail_server' => $mailServer,
                'mail_from' => $mailFrom,
                'mail_recipients' => $mailRecipients
            ],
            static fn($value) => $value !== null
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/check'], $data),
        );

        $this->expectOutputRegex('/\{"status":"ERROR","description":"Missing Mail parameters"/');

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function save()
    {
        $data = [
            'mail_enabled' => true,
            'mail_server' => self::$faker->domainName(),
            'mail_port' => self::$faker->randomNumber(3),
            'mail_user' => self::$faker->userName(),
            'mail_pass' => self::$faker->password(),
            'mail_security' => 'tls',
            'mail_from' => self::$faker->email(),
            'mail_auth_enabled' => self::$faker->boolean(),
            'mail_recipients' => self::$faker->email()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/save'], $data)
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function saveWithNoChanges()
    {
        $data = [
            'mail_enabled' => false
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/save'], $data)
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    #[TestWith(['', ''])]
    #[TestWith(['test_server', ''])]
    #[TestWith(['', 'me@email.com'])]
    public function saveWithMissingParameters(?string $mailServer, ?string $mailFrom)
    {
        $data = [
            'mail_enabled' => true,
            'mail_server' => $mailServer,
            'mail_from' => $mailFrom
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/save'], $data)
        );

        $this->expectOutputString('{"status":"ERROR","description":"Missing Mail parameters","data":null}');

        IntegrationTestCase::runApp($container);
    }

    /**
     * Turning mail on from a disabled state persists every field posted and records that it was
     * enabled — checked against a real ConfigData instance (not just the JSON envelope), so a
     * change that stops writing one of the fields would fail here even though the response looks
     * unchanged. This also drives the auth branch with a real password, distinct from the "already
     * enabled" state every other test in this class starts from (see getConfigData() below).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingEnablesMailFromDisabledAndStoresAuthCredentials(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildMailContainer(
            $configData,
            [
                'mail_enabled' => 'on',
                'mail_server' => 'smtp.example.org',
                'mail_port' => 587,
                'mail_security' => 'tls',
                'mail_from' => 'noreply@example.org',
                'mail_auth_enabled' => 'on',
                'mail_user' => 'smtpuser',
                'mail_pass' => 'S3cr3tPass!',
                'mail_recipients' => 'admin@example.org',
            ]
        );

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertTrue($configData->isMailEnabled());
        self::assertSame('smtp.example.org', $configData->getMailServer());
        self::assertSame('smtpuser', $configData->getMailUser());
        self::assertSame('S3cr3tPass!', $configData->getMailPass());

        $mailEvents = $this->mailEvents($receiver);

        self::assertCount(1, $mailEvents);
        self::assertSame('Mail enabled', $mailEvents[0]->getEventMessage()?->composeText());
    }

    /**
     * The masked placeholder the form redisplays for an already-stored password ('***') must
     * never overwrite the real one — posting it back has to leave the stored password exactly as
     * it was, or every save that doesn't type a fresh password would blank/corrupt it.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithTheMaskedPasswordPlaceholderDoesNotOverwriteTheStoredPassword(): void
    {
        $configData = $this->newConfigData();
        $configData->setMailEnabled(true);
        $configData->setMailServer('smtp.example.org');
        $configData->setMailFrom('noreply@example.org');
        $configData->setMailAuthenabled(true);
        $configData->setMailUser('smtpuser');
        $configData->setMailPass('OriginalPass1');

        $container = $this->buildMailContainer(
            $configData,
            [
                'mail_enabled' => 'on',
                'mail_server' => 'smtp.example.org',
                'mail_from' => 'noreply@example.org',
                'mail_auth_enabled' => 'on',
                'mail_user' => 'smtpuser',
                'mail_pass' => '***',
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertSame('OriginalPass1', $configData->getMailPass());
    }

    /**
     * Posting the form with mail left off, when it was already off, moves nothing: the endpoint
     * reports "No changes" instead of a misleading "Configuration updated", and the save is never
     * called. Every other test in this class starts from "already enabled" (see getConfigData()),
     * so this is the only one that reaches the final else branch.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithMailAlreadyDisabledReportsNoChanges(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildMailContainer($configData, []);

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"No changes","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isMailEnabled());
        self::assertSame([], $this->mailEvents($receiver));
    }

    /**
     * A ConfigData populated with just enough to satisfy the framework's own bootstrap checks
     * (installed, not in maintenance, a current app/database version) so the request reaches the
     * controller instead of being redirected by them. Mail starts disabled, unlike the class-wide
     * getConfigData() override below, which every other test in this file relies on.
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
     * Builds the request container for `configMail/save`, wiring the given ConfigData instance as
     * the one the controller reads and, since it is a real mutable object (not a canned stub),
     * writes back into — which is what lets the tests above assert on it directly after the
     * request runs.
     *
     * @param array<string, string|int> $postFields
     *
     * @throws Exception
     */
    private function buildMailContainer(ConfigDataInterface $configData, array $postFields): ContainerInterface
    {
        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        return $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configMail/save'], $postFields),
            [ConfigFileService::class => $configFileService]
        );
    }

    /**
     * Attaches a recorder to the container's real event dispatcher so a test can check whether
     * the controller notified an event, without needing to mock the dispatcher itself (it is
     * injected as its concrete, final class, so it cannot be doubled).
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
    private function mailEvents(EventReceiver $receiver): array
    {
        return array_values(
            array_filter(
                $receiver->events,
                static fn(Event $event): bool => $event->getName() === 'save.config.mail'
            )
        );
    }

    protected function getConfigData(): array
    {
        $configData = parent::getConfigData();
        $configData['isMailEnabled'] = true;

        return $configData;
    }
}
