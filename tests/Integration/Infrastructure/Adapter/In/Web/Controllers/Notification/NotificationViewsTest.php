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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Notification\Models\Notification;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\NotificationDataGenerator;
use SP\Domain\User\Dtos\UserDto;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the notification endpoints the existing tests left out: the listing, the search grid,
 * the create form and the edit save.
 */
#[Group('integration')]
class NotificationViewsTest extends IntegrationTestCase
{
    /**
     * The harness builds a fresh random user every time it is asked for one, so a test that has to
     * know who is signed in — the edit does, since it only writes the caller's own notifications —
     * has to hold on to the first one.
     */
    private ?UserDto $signedInUser = null;

    protected function getUserDataDto(): UserDto
    {
        return $this->signedInUser ??= parent::getUserDataDto();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSearch')]
    public function search()
    {
        $generator = NotificationDataGenerator::factory();

        $this->addDatabaseMapperResolver(
            Notification::class,
            QueryResult::withTotalNumRows([$generator->buildNotification(), $generator->buildNotification()], 2)
        );

        $this->whenRequesting('notification/search');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIndex')]
    public function index()
    {
        $generator = NotificationDataGenerator::factory();

        $this->addDatabaseMapperResolver(
            Notification::class,
            QueryResult::withTotalNumRows([$generator->buildNotification()], 1)
        );

        $this->whenRequesting('notification/index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function create()
    {
        $this->whenRequesting('notification/create');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        // The save reads the notification back before writing it — that is where it establishes
        // the row is the signed-in user's to edit — so the lookup has to answer with one of theirs.
        $this->addDatabaseMapperResolver(
            Notification::class,
            new QueryResult(
                [
                    NotificationDataGenerator::factory()
                                             ->buildNotification()
                                             ->mutate(['id' => 100, 'userId' => $this->getUserDataDto()->id]),
                ],
                1
            )
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'notification/saveEdit/100'],
                [
                    'notification_type' => 'sysPass',
                    'notification_component' => 'sysPass',
                    'notification_description' => 'An edited notification',
                    // A notification with nobody to deliver it to is refused, so the target is
                    // part of the payload rather than optional.
                    'notification_user' => 5,
                ]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Notification updated","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route])
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblNotifications"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }

    /**
     * The listing is a full page rather than a fragment, so it carries the grid inside it.
     */
    private function outputCheckerIndex(string $output): void
    {
        self::assertStringContainsString('tblNotifications', $output);
    }

    /**
     * The form offers the fields a notification is composed of.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $fields = $crawler->filterXPath('//form//input|//form//textarea|//form//select')->extract(['name']);

        self::assertContains('notification_description', $fields);
    }
}
