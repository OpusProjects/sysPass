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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Security\Models\Eventlog;
use SP\Domain\User\Models\ProfileData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the event log's listing and search. Only clearing it had a test.
 *
 * The log is the record of what happened, so being able to read it is the point; a listing that
 * rendered no rows would look like an empty history rather than a broken page.
 */
#[Group('integration')]
class EventlogViewsTest extends IntegrationTestCase
{
    private const RECORDED_ACTION = 'edit.user.password';

    /**
     * Reading the log is gated behind its own permission.
     */
    protected function getUserProfile(): ProfileData
    {
        return new ProfileData(['evl' => true]);
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
        $this->addDatabaseMapperResolver(
            Eventlog::class,
            QueryResult::withTotalNumRows([$this->buildEntry(), $this->buildEntry()], 2)
        );

        $this->whenRequesting('eventlog/search');
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
        $this->addDatabaseMapperResolver(
            Eventlog::class,
            QueryResult::withTotalNumRows([$this->buildEntry()], 1)
        );

        $this->whenRequesting('eventlog/index');
    }

    private function buildEntry(): Eventlog
    {
        return new Eventlog(
            [
                'id' => self::$faker->randomNumber(3),
                'date' => self::$faker->unixTime(),
                'login' => 'someone',
                'userId' => 1,
                'ipAddress' => '203.0.113.10',
                'action' => self::RECORDED_ACTION,
                'description' => 'Something happened',
                'level' => 'INFO',
            ]
        );
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

    /**
     * A row per entry, and the recorded action reaches the page — a listing that rendered rows
     * without their content would still satisfy a row count alone.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblEventLog"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
        self::assertStringContainsString(self::RECORDED_ACTION, $json->data->html);
    }

    private function outputCheckerIndex(string $output): void
    {
        self::assertStringContainsString('tblEventLog', $output);
    }
}
