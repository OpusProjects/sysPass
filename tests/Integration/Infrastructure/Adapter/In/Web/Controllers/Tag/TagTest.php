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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Tag;

use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Tag\Models\Tag;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\TagGenerator;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class TagTest
 */
#[Group('integration')]
class TagTest extends IntegrationTestCase
{
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/create'])
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
        $this->addDatabaseMapperResolver(
            Tag::class,
            new QueryResult([TagGenerator::factory()->buildTag()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Editing a tag id that no longer exists (deleted between listing and click) must fail
     * with a clear message instead of rendering a form around an empty/default Tag.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editNotFound()
    {
        // No mapper resolver registered for Tag::class: the default stub answers with an
        // empty result set (0 rows), exactly like a lookup for a missing id would.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/edit/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Tag not found","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function view()
    {
        $this->addDatabaseMapperResolver(
            Tag::class,
            new QueryResult([TagGenerator::factory()->buildTag()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/view/100'])
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Tag removed","data":null}');
    }

    /**
     * Deleting an id that no longer exists must be reported as a failure rather than a
     * silent no-op: the repository's DELETE affects zero rows, and the service turns that
     * into "Tag not found" instead of a false "Tag removed".
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingleNotFound()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 0, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/delete/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Tag not found","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/delete', 'items' => [100, 200, 300]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Tags deleted","data":null}');
    }

    /**
     * If the DELETE affects fewer rows than ids were sent (one of them no longer existed,
     * or a race deleted it first), the batch must be reported as a failure rather than a
     * partial, silently-accepted success.
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while removing the tags","data":null}');
    }

    /**
     * Deleting with nothing selected is rejected rather than issuing an unbounded statement.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteWithoutSelection()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/delete', 'items' => []])
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
    public function saveCreate()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'tag/saveCreate'],
                ['name' => self::$faker->colorName()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Tag added","data":null}');
    }

    /**
     * The form itself is the first guard: a tag with no name must never reach the
     * repository, since a nameless tag can't be told apart from any other in the UI.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateMissingName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'tag/saveCreate'], [])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A tag name is needed","data":null}');
    }

    /**
     * The repository runs a duplicate check (by name or hash) before the INSERT. Answering
     * it with a row must refuse the save instead of creating a second tag with the same name.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateDuplicate()
    {
        $this->databaseQueryResolver = $this->duplicateCheckResolver();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'tag/saveCreate'],
                ['name' => self::$faker->colorName()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated tag","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'tag/saveEdit/100'],
                ['name' => self::$faker->colorName()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Tag updated","data":null}');
    }

    /**
     * Same guard as create: editing into a blank name is refused before any query runs.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditMissingName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'tag/saveEdit/100'], [])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A tag name is needed","data":null}');
    }

    /**
     * Renaming a tag to collide with another existing one (checkDuplicatedOnUpdate) must be
     * refused rather than silently merging two tags under the same name.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditDuplicate()
    {
        $this->databaseQueryResolver = $this->duplicateCheckResolver();

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'tag/saveEdit/100'],
                ['name' => self::$faker->colorName()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated tag","data":null}');
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
        $tagGenerator = TagGenerator::factory();

        $this->addDatabaseMapperResolver(
            Tag::class,
            QueryResult::withTotalNumRows([$tagGenerator->buildTag(), $tagGenerator->buildTag()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'tag/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Both checkDuplicatedOnAdd and checkDuplicatedOnUpdate run as a bare SELECT against the
     * `Tag` table (no mapper class) before the write. Answering it with a row simulates an
     * existing tag with a colliding name/hash. Init also loads the session-timeout preset
     * through an unrelated bare SELECT on every request, so the match has to be narrowed to
     * the `Tag` table rather than "any SELECT" or it hands that query a row it can't map.
     */
    private function duplicateCheckResolver(): Closure
    {
        return function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'SELECT') && str_contains($statement, '`Tag`')) {
                return new QueryResult([1]);
            }

            if (str_starts_with($statement, 'INSERT') || str_starts_with($statement, 'UPDATE')) {
                self::fail('The write must not run once a duplicate was found: '.$statement);
            }

            return new QueryResult([], 1, 100);
        };
    }

    /**
     * The tag form carries the name field plus the framework's hidden inputs.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $filter = $crawler->filterXPath('//div[@id="box-popup"]//form[@name="frmTags"]//input')
                          ->extract(['name']);

        self::assertContains('name', $filter);
    }

    /**
     * One row per tag returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath('//table/tbody[@id="data-rows-tblTags"]//tr[string-length(@data-item-id) > 0]');

        self::assertCount(2, $rows);
    }
}
