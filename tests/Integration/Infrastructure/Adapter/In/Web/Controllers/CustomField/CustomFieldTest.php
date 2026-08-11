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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\CustomField;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\CustomField\Models\CustomFieldDefinition;
use SP\Domain\CustomField\Models\CustomFieldDefinitionList;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class CustomFieldTest
 */
#[Group('integration')]
class CustomFieldTest extends IntegrationTestCase
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/create'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNamedField')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(
            CustomFieldDefinition::class,
            new QueryResult([$this->buildDefinition()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNamedField')]
    public function view()
    {
        $this->addDatabaseMapperResolver(
            CustomFieldDefinition::class,
            new QueryResult([$this->buildDefinition()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/view/100'])
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Field deleted","data":null}');
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
                ['r' => 'customField/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Fields deleted","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/delete', 'items' => []])
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
                ['r' => 'customField/saveCreate'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Field added","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        // The service loads the field before updating it, so it has to resolve.
        $this->addDatabaseMapperResolver(
            CustomFieldDefinition::class,
            new QueryResult([$this->buildDefinition()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveEdit/100'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Field updated","data":null}');
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
        // The search maps rows to the list model (it carries the joined type name), so the
        // grid is only populated when that class is the one resolved.
        $this->addDatabaseMapperResolver(
            CustomFieldDefinitionList::class,
            QueryResult::withTotalNumRows([$this->buildListItem(), $this->buildListItem()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function buildListItem(): CustomFieldDefinitionList
    {
        return new CustomFieldDefinitionList(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::$faker->colorName(),
                'moduleId' => 10,
                'typeId' => 1,
                'required' => 0,
                'showInList' => 0,
                'isEncrypted' => 0,
                'typeName' => 'text',
            ]
        );
    }

    private function buildDefinition(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::$faker->colorName(),
                'moduleId' => 10,
                'typeId' => 1,
                'required' => 0,
                'showInList' => 0,
                'isEncrypted' => 0,
                'help' => self::$faker->sentence(),
            ]
        );
    }

    /**
     * The definition form carries the name plus the type and module selects.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $inputs = $crawler->filterXPath('//form[@name="frmCustomFields"]//input')->extract(['name']);
        $selects = $crawler->filterXPath('//form[@name="frmCustomFields"]//select')->extract(['name']);

        self::assertContains('name', $inputs);
        self::assertContains('type', $selects);
        self::assertContains('module', $selects);
    }

    /**
     * Editing or viewing renders the stored definition, so its name reaches the form.
     */
    private function outputCheckerNamedField(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $value = $crawler->filterXPath('//form[@name="frmCustomFields"]//input[@name="name"]')->extract(['value']);

        self::assertNotEmpty($value);
        self::assertNotSame('', $value[0]);
    }

    /**
     * One row per definition returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblCustomFields"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }
}
