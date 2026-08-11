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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Track;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Security\Models\Track;
use SP\Domain\User\Models\ProfileData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the track endpoints, which are how an administrator inspects and lifts the brute-force
 * lockouts. None of them had tests.
 */
#[Group('integration')]
class TrackTest extends IntegrationTestCase
{
    private const TRACKED_ADDRESS = '203.0.113.7';

    /**
     * Every track action is gated behind user management.
     *
     * Note this only shapes the rendered page: the integration harness stubs AclInterface so
     * checkUserAccess() always allows, so a refusal cannot be exercised from here.
     */
    protected function getUserProfile(): ProfileData
    {
        return new ProfileData(['mgmUsers' => true]);
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
            Track::class,
            QueryResult::withTotalNumRows([$this->buildTrack(), $this->buildTrack()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'track/search'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Lifting a lockout is the operation that lets a locked-out account try again.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function unlock()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'track/unlock/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Track unlocked","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clear()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'track/clear'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Tracks cleared out","data":null}');
    }

    private function buildTrack(): Track
    {
        return new Track(
            [
                'id' => self::$faker->randomNumber(3),
                'userId' => self::$faker->randomNumber(2),
                'source' => 'login',
                'time' => self::$faker->unixTime(),
                'timeUnlock' => null,
                // The column holds a packed address; the grid renders it via Address::fromBinary().
                'ipv4' => inet_pton(self::TRACKED_ADDRESS),
                'tracked' => 1,
            ]
        );
    }

    /**
     * One row per recorded attempt.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblTracks"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);

        // The packed address has to come back as the dotted form an administrator can read.
        self::assertStringContainsString(self::TRACKED_ADDRESS, $json->data->html);
    }
}
