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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Models\PublicLink;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\PublicLinkDataGenerator;
use SP\Tests\Support\InjectCrypt;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class PublicLinkTest
 */
#[Group('integration')]
// Creating or re-keying a link re-encrypts the account's data with the master password, which
// is held in the session vault.
#[InjectVault]
class PublicLinkTest extends IntegrationTestCase
{
    /** Documentation-range address, so it is unmistakable in the rendered history. */
    private const VISITOR_ADDRESS = '203.0.113.42';

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/create'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(PublicLink::class, new QueryResult([$this->buildPublicLink()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A link's usage history is a serialized blob, deserialized with class loading disabled.
     * Viewing has to render it without tripping over that.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerView')]
    public function view()
    {
        $this->addDatabaseMapperResolver(
            PublicLink::class,
            new QueryResult([$this->buildPublicLink(self::useInfo())])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingle()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Link deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiple()
    {
        // The batch delete asserts that as many rows were affected as ids were sent.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 2, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'publicLink/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Links deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteWithoutSelection()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/delete', 'items' => []])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"No items selected","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[InjectCrypt]
    public function saveCreate()
    {
        $this->givenAnAccountToLinkTo();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'publicLink/saveCreate'],
                ['accountId' => self::$faker->randomNumber(3), 'notify' => 'on']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Link created","data":null}');
    }

    /**
     * Creating a link straight from an account view is a separate endpoint taking the account
     * on the route.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[InjectCrypt]
    public function saveCreateFromAccount()
    {
        $this->givenAnAccountToLinkTo();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'publicLink/saveCreateFromAccount/100/1']
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Link created","data":null}');
    }

    /**
     * Refreshing re-keys an existing link so the old URL stops working.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[InjectCrypt]
    public function refresh()
    {
        $this->givenAnAccountToLinkTo($this->buildPublicLink());

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/refresh/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Link updated","data":null}');
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
            PublicLink::class,
            QueryResult::withTotalNumRows([$this->buildPublicLink(), $this->buildPublicLink()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'publicLink/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A link wraps the account's own data, which is read through getDataForLink(). That query
     * declares no map class, so it comes back as the default Simple model.
     */
    private function givenAnAccountToLinkTo(?PublicLink $link = null): void
    {
        $account = new Simple([
            'id' => 100,
            'name' => self::$faker->colorName(),
            'clientName' => self::$faker->colorName(),
            'pass' => self::$faker->sha1(),
            'key' => self::$faker->sha1(),
        ]);

        // Both the account lookup and the "does this account already have a link" check come
        // back as Simple, so they have to be told apart by the statement: answering the second
        // one with a row makes the endpoint refuse with "Link already created".
        $this->databaseQueryResolver = function (QueryData $queryData) use ($account, $link): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'account_data_v')) {
                return new QueryResult([$account]);
            }

            // Refreshing loads the link it is re-keying; creating must not find one.
            if ($link !== null && str_contains($statement, 'PublicLink') && str_starts_with($statement, 'SELECT')) {
                return new QueryResult([$link]);
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * A serialized usage record, in the shape PublicLink::getUseInfo() stores.
     */
    private static function useInfo(): string
    {
        return serialize(
            [
                [
                    'who' => self::VISITOR_ADDRESS,
                    'time' => self::$faker->unixTime(),
                    'hash' => self::$faker->sha1(),
                    'agent' => self::$faker->userAgent(),
                    'https' => true,
                ],
            ]
        );
    }

    private function buildPublicLink(?string $useInfo = null): PublicLink
    {
        $publicLink = PublicLinkDataGenerator::factory()->buildPublicLink();

        return $useInfo === null ? $publicLink : $publicLink->mutate(['useInfo' => $useInfo]);
    }

    /**
     * The link form offers the account picker.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);

        self::assertGreaterThan(0, $crawler->filterXPath('//form[@name="frmPublickLink"]')->count());
    }

    /**
     * Viewing a link renders its usage history, so the serialized blob has to come back as a
     * row per recorded visit.
     */
    private function outputCheckerView(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);

        self::assertGreaterThan(0, $crawler->filterXPath('//form[@name="frmPublickLink"]')->count());

        // The usage history is a serialized blob on the row; the recorded visitor address only
        // reaches the page if it was deserialized and rendered.
        self::assertStringContainsString(self::VISITOR_ADDRESS, $json->data->html);
    }

    /**
     * One row per link returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath('//table/tbody[@id="data-rows-tblLinks"]//tr[string-length(@data-item-id) > 0]');

        self::assertCount(2, $rows);
    }
}
