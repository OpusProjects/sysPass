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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigDokuWiki;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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
 * DokuWiki integration needs an XML-RPC endpoint to talk to, so the controller refuses to
 * enable it without both the RPC URL and the site's base URL, but leaves the connection
 * credentials optional (an anonymous or unauthenticated wiki has none). These tests drive
 * the real `ConfigData` object through the controller (rather than checking only the JSON
 * envelope) so a change that stops writing one of those fields, or that clears them on
 * disable, would fail here even though the "OK" response looks unchanged.
 */
#[Group('integration')]
class ConfigDokuWikiTest extends IntegrationTestCase
{
    /**
     * Enabling DokuWiki with a full set of connection details, including credentials,
     * persists every one of them and records that the feature was turned on.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingEnablesDokuWikiAndStoresItsConnectionDetails(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildDokuWikiContainer(
            $configData,
            [
                'dokuwiki_enabled' => 'on',
                'dokuwiki_url' => 'https://doku.example.org/lib/exe/xmlrpc.php',
                'dokuwiki_urlbase' => 'https://doku.example.org/',
                'dokuwiki_user' => 'svc_wiki',
                'dokuwiki_pass' => 'a_password123',
                'dokuwiki_namespace' => 'internal:docs',
            ]
        );

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertTrue($configData->isDokuwikiEnabled());
        self::assertSame('https://doku.example.org/lib/exe/xmlrpc.php', $configData->getDokuwikiUrl());
        self::assertSame('https://doku.example.org/', $configData->getDokuwikiUrlBase());
        self::assertSame('svc_wiki', $configData->getDokuwikiUser());
        self::assertSame('a_password123', $configData->getDokuwikiPass());
        self::assertSame('internal:docs', $configData->getDokuwikiNamespace());

        $dokuWikiEvents = $this->dokuWikiEvents($receiver);

        self::assertCount(1, $dokuWikiEvents);
        self::assertSame('DokuWiki enabled', $dokuWikiEvents[0]->getEventMessage()?->composeText());
    }

    /**
     * The connection credentials (user, password, namespace) are not checked at all —
     * DokuWiki can be enabled anonymously with just its two URLs, so the optional fields
     * are simply stored as whatever was posted, empty or not.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function enablingDokuWikiWithoutOptionalCredentialsStillSucceeds(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildDokuWikiContainer(
            $configData,
            [
                'dokuwiki_enabled' => 'on',
                'dokuwiki_url' => 'https://doku.example.org/lib/exe/xmlrpc.php',
                'dokuwiki_urlbase' => 'https://doku.example.org/',
            ]
        );

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertTrue($configData->isDokuwikiEnabled());
        self::assertSame('https://doku.example.org/lib/exe/xmlrpc.php', $configData->getDokuwikiUrl());
        self::assertSame('https://doku.example.org/', $configData->getDokuwikiUrlBase());
        self::assertNull($configData->getDokuwikiUser());
        self::assertNull($configData->getDokuwikiPass());
        self::assertNull($configData->getDokuwikiNamespace());
    }

    /**
     * The RPC URL is mandatory once the feature is switched on: without it there is nothing
     * for sysPass to talk to, so the save is refused before anything is persisted.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function enablingDokuWikiWithoutUrlIsRefused(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildDokuWikiContainer(
            $configData,
            [
                'dokuwiki_enabled' => 'on',
                'dokuwiki_urlbase' => 'https://doku.example.org/',
            ]
        );

        $this->expectOutputString(
            '{"status":"ERROR","description":"Missing DokuWiki parameters","data":null}'
        );

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isDokuwikiEnabled());
        self::assertNull($configData->getDokuwikiUrl());
    }

    /**
     * The base URL is required too: it is what turns a page name into a link the user can
     * actually open, so leaving it out is refused just like the RPC URL is.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function enablingDokuWikiWithoutUrlBaseIsRefused(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildDokuWikiContainer(
            $configData,
            [
                'dokuwiki_enabled' => 'on',
                'dokuwiki_url' => 'https://doku.example.org/lib/exe/xmlrpc.php',
            ]
        );

        $this->expectOutputString(
            '{"status":"ERROR","description":"Missing DokuWiki parameters","data":null}'
        );

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isDokuwikiEnabled());
        self::assertNull($configData->getDokuwikiUrlBase());
    }

    /**
     * Turning DokuWiki off only flips the enabled flag; it deliberately leaves the URLs and
     * credentials in place so re-enabling it later does not need them typed in again.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function disablingDokuWikiTurnsOffTheFlagButKeepsThePreviousSettings(): void
    {
        $configData = $this->newConfigData();
        $configData->setDokuwikiEnabled(true);
        $configData->setDokuwikiUrl('https://doku.example.org/lib/exe/xmlrpc.php');
        $configData->setDokuwikiUrlBase('https://doku.example.org/');
        $configData->setDokuwikiUser('svc_wiki');
        $configData->setDokuwikiPass('a_password123');
        $configData->setDokuwikiNamespace('internal:docs');

        $container = $this->buildDokuWikiContainer($configData, []);

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isDokuwikiEnabled());
        self::assertSame('https://doku.example.org/lib/exe/xmlrpc.php', $configData->getDokuwikiUrl());
        self::assertSame('https://doku.example.org/', $configData->getDokuwikiUrlBase());
        self::assertSame('svc_wiki', $configData->getDokuwikiUser());
        self::assertSame('a_password123', $configData->getDokuwikiPass());
        self::assertSame('internal:docs', $configData->getDokuwikiNamespace());

        $dokuWikiEvents = $this->dokuWikiEvents($receiver);

        self::assertCount(1, $dokuWikiEvents);
        self::assertSame('DokuWiki disabled', $dokuWikiEvents[0]->getEventMessage()?->composeText());
    }

    /**
     * Posting the form with DokuWiki left off, when it was already off, moves nothing —
     * the endpoint reports "No changes" instead of a misleading "Configuration updated",
     * and the configuration is never written.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithDokuWikiAlreadyDisabledReportsNoChanges(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildDokuWikiContainer($configData, []);

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"No changes","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isDokuwikiEnabled());
        self::assertSame([], $this->dokuWikiEvents($receiver));
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
     * Builds the request container for `configDokuWiki/save`, wiring the given `ConfigData`
     * instance as the one the controller reads and, since it is a real mutable object
     * (not a canned stub), writes back into — which is what lets the tests assert on it
     * directly after the request runs.
     *
     * @param array<string, string> $postFields
     *
     * @throws Exception
     */
    private function buildDokuWikiContainer(ConfigDataInterface $configData, array $postFields): ContainerInterface
    {
        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        return $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configDokuWiki/save'], $postFields),
            [ConfigFileService::class => $configFileService]
        );
    }

    /**
     * Attaches a recorder to the container's real event dispatcher so a test can check
     * whether the controller notified an event, without needing to mock the dispatcher
     * itself (it is injected as its concrete, final class, so it cannot be doubled).
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
    private function dokuWikiEvents(EventReceiver $receiver): array
    {
        return array_values(
            array_filter(
                $receiver->events,
                static fn(Event $event): bool => $event->getName() === 'save.config.dokuwiki'
            )
        );
    }
}
