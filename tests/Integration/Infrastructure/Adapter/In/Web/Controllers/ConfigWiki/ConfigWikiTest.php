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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigWiki;

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
 * The Wiki search box is only useful once it points somewhere, so the controller refuses to
 * turn it on without a search URL, a page URL and a page-name filter, and it persists exactly
 * what was posted into the stored configuration. These tests drive the real `ConfigData`
 * object through the controller (rather than checking only the JSON envelope) so a change
 * that stops writing one of those fields, or that clears the URLs on disable, would fail
 * here even though the "OK" response looks unchanged.
 */
#[Group('integration')]
class ConfigWikiTest extends IntegrationTestCase
{
    /**
     * Enabling the Wiki with all three settings present persists every one of them and
     * records that the feature was turned on.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingEnablesWikiAndStoresItsSearchSettings(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildWikiContainer(
            $configData,
            [
                'wiki_enabled' => 'on',
                'wiki_searchurl' => 'https://wiki.example.org/search',
                'wiki_pageurl' => 'https://wiki.example.org/page',
                'wiki_filter' => 'Home,FAQ,Install',
            ]
        );

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertTrue($configData->isWikiEnabled());
        self::assertSame('https://wiki.example.org/search', $configData->getWikiSearchurl());
        self::assertSame('https://wiki.example.org/page', $configData->getWikiPageurl());
        self::assertSame(['Home', 'FAQ', 'Install'], $configData->getWikiFilter());

        $wikiEvents = $this->wikiEvents($receiver);

        self::assertCount(1, $wikiEvents);
        self::assertSame('Wiki enabled', $wikiEvents[0]->getEventMessage()?->composeText());
    }

    /**
     * A search URL is mandatory once the feature is switched on: without it, the box would
     * have nothing to query, so the save is refused before anything is persisted.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithWikiEnabledButNoSearchUrlIsRefused(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildWikiContainer(
            $configData,
            [
                'wiki_enabled' => 'on',
                'wiki_pageurl' => 'https://wiki.example.org/page',
                'wiki_filter' => 'Home,FAQ',
            ]
        );

        $this->expectOutputString('{"status":"ERROR","description":"Missing Wiki parameters","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isWikiEnabled());
        self::assertNull($configData->getWikiSearchurl());
    }

    /**
     * Same as the missing search URL: a page URL is required too, since it is what turns a
     * search hit into a link the user can follow.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithWikiEnabledButNoPageUrlIsRefused(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildWikiContainer(
            $configData,
            [
                'wiki_enabled' => 'on',
                'wiki_searchurl' => 'https://wiki.example.org/search',
                'wiki_filter' => 'Home,FAQ',
            ]
        );

        $this->expectOutputString('{"status":"ERROR","description":"Missing Wiki parameters","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isWikiEnabled());
        self::assertNull($configData->getWikiPageurl());
    }

    /**
     * The page-name filter is the third mandatory field: without it there is nothing to tell
     * a Wiki search result apart from an ordinary one, so it is required just like the URLs.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithWikiEnabledButNoFilterIsRefused(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildWikiContainer(
            $configData,
            [
                'wiki_enabled' => 'on',
                'wiki_searchurl' => 'https://wiki.example.org/search',
                'wiki_pageurl' => 'https://wiki.example.org/page',
            ]
        );

        $this->expectOutputString('{"status":"ERROR","description":"Missing Wiki parameters","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isWikiEnabled());
        self::assertSame([], $configData->getWikiFilter());
    }

    /**
     * Turning the Wiki off only flips the enabled flag; it deliberately leaves the search
     * URL, page URL and filter in place so re-enabling it later does not need them typed in
     * again.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function disablingWikiTurnsOffTheFlagButKeepsThePreviousUrls(): void
    {
        $configData = $this->newConfigData();
        $configData->setWikiEnabled(true);
        $configData->setWikiSearchurl('https://wiki.example.org/search');
        $configData->setWikiPageurl('https://wiki.example.org/page');
        $configData->setWikiFilter(['Home', 'FAQ']);

        $container = $this->buildWikiContainer($configData, []);

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"Configuration updated","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isWikiEnabled());
        self::assertSame('https://wiki.example.org/search', $configData->getWikiSearchurl());
        self::assertSame('https://wiki.example.org/page', $configData->getWikiPageurl());
        self::assertSame(['Home', 'FAQ'], $configData->getWikiFilter());

        $wikiEvents = $this->wikiEvents($receiver);

        self::assertCount(1, $wikiEvents);
        self::assertSame('Wiki disabled', $wikiEvents[0]->getEventMessage()?->composeText());
    }

    /**
     * Posting the form with the Wiki left off, when it was already off, moves nothing —
     * the endpoint reports "No changes" instead of a misleading "Configuration updated",
     * and the configuration is never written.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    #[Test]
    public function savingWithWikiAlreadyDisabledReportsNoChanges(): void
    {
        $configData = $this->newConfigData();

        $container = $this->buildWikiContainer($configData, []);

        $receiver = $this->attachEventRecorder($container);

        $this->expectOutputString('{"status":"OK","description":"No changes","data":null}');

        IntegrationTestCase::runApp($container);

        self::assertFalse($configData->isWikiEnabled());
        self::assertSame([], $this->wikiEvents($receiver));
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
     * Builds the request container for `configWiki/save`, wiring the given `ConfigData`
     * instance as the one the controller reads and, since it is a real mutable object
     * (not a canned stub), writes back into — which is what lets the tests assert on it
     * directly after the request runs.
     *
     * @param array<string, string> $postFields
     *
     * @throws Exception
     */
    private function buildWikiContainer(ConfigDataInterface $configData, array $postFields): ContainerInterface
    {
        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        return $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'configWiki/save'], $postFields),
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
    private function wikiEvents(EventReceiver $receiver): array
    {
        return array_values(
            array_filter(
                $receiver->events,
                static fn(Event $event): bool => $event->getName() === 'save.config.wiki'
            )
        );
    }
}
