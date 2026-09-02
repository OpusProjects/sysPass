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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Category;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Category\Models\Category;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\CategoryGenerator;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class CategoryTest
 */
#[Group('integration')]
class CategoryTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerCreate')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/create'])
        );

        IntegrationTestCase::runApp($container);
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
            return new QueryResult([], 3, 0);
        };
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/delete', 'items' => [100, 200, 300]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Categories deleted","data":null}');
    }

    /**
     * If the DELETE affects fewer rows than ids were sent — one of them no longer existed, or
     * another session removed it first — the batch is reported as a failure rather than as a
     * partial success. This service used to refuse only when *nothing* matched, so a selection of
     * which one item was already gone came back as the whole selection removed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialFailure()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            // Only 1 of the 2 requested ids was actually removed.
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting categories","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Category deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerEdit')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(
            Category::class,
            new QueryResult([CategoryGenerator::factory()->buildCategory()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreate()
    {
        $data = [
            'name' => self::$faker->name(),
            'description' => self::$faker->text()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'category/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Category added","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $data = [
            'name' => self::$faker->name(),
            'description' => self::$faker->text()
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'category/saveEdit/100'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Category updated","data":null}');
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
        $categoryGenerator = CategoryGenerator::factory();

        $this->addDatabaseMapperResolver(
            Category::class,
            QueryResult::withTotalNumRows(
                [
                    $categoryGenerator->buildCategory(),
                    $categoryGenerator->buildCategory()
                ],
                2
            )
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerView')]
    public function view()
    {
        $this->addDatabaseMapperResolver(
            Category::class,
            new QueryResult([CategoryGenerator::factory()->buildCategory()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerCreate(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmCategories"]//select|//input'
        )->extract(['_name']);

        self::assertCount(3, $filter);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerEdit(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmCategories"]//select|//input'
        )->extract(['_name']);

        self::assertCount(3, $filter);
        self::assertEquals('OK', $json->status);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblCategories"]//tr[string-length(@data-item-id) > 0]'
        )->extract(['data-item-id']);

        self::assertCount(2, $filter);
        self::assertEquals('OK', $json->status);
    }

    /**
     * @param string $output
     * @return void
     */
    private function outputCheckerView(string $output): void
    {
        $json = json_decode($output);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath(
            '//div[@id="box-popup"]//form[@name="frmCategories"]//select|//input'
        )->extract(['_name']);

        self::assertCount(3, $filter);
        self::assertEquals('OK', $json->status);
    }
}
