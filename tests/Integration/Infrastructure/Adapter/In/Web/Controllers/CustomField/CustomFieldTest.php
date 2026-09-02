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
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
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
     * Editing a definition id that no longer exists must fail with a clear message instead
     * of rendering a form around an empty/default definition.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editNotFound()
    {
        // No mapper resolver registered for CustomFieldDefinition::class: the default stub
        // answers with an empty result set (0 rows), exactly like a lookup for a missing id.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/edit/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field not found","data":null}');
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
     * Viewing is gated on its own permission, checked before the definition is even read.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function viewDeniedByAcl()
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/view/100']),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * Mirrors editNotFound above: viewing a definition id that no longer exists must fail with
     * a clear message instead of rendering a view around an empty/default definition.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function viewNotFound()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/view/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field not found","data":null}');
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
     * Deleting an id that no longer exists must be reported as a failure rather than a
     * silent no-op: the repository's DELETE affects zero rows, and the service turns that
     * into "Field not found" instead of a false "Field deleted".
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/delete/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field not found","data":null}');
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
     * When none of the requested ids matched a row, the transactionAware() closure throws, the
     * transaction rolls back, and the batch is reported as a failure.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultipleNoneFound()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            // Neither of the 2 requested ids matched a row.
            return new QueryResult([], 0, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the fields","data":null}');
    }

    /**
     * ...and so is a PARTIAL match, where some of the requested ids existed and some did not.
     *
     * It used not to be. The `=== 0` guard refused only when nothing at all matched, so a
     * selection of which one item had already been deleted came back as "Fields deleted" while
     * some of the requested rows were never removed. This test recorded that as the current,
     * weaker-than-Tag behaviour; it now asserts the refusal, which is what Tag has always done.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialMatchIsRefused()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            // Only 1 of the 2 requested ids actually matched a row.
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'customField/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the fields","data":null}');
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
     * The form's first guard: a definition with no name must never reach the repository.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateMissingName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveCreate'],
                ['type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field name not set","data":null}');
    }

    /**
     * The type select defaults to a placeholder option that posts 0; the form must refuse
     * that rather than persist a definition with no real type.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateMissingType()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveCreate'],
                ['name' => self::$faker->colorName(), 'type' => 0, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field type not set","data":null}');
    }

    /**
     * Same guard for the module select: 0 means "nothing chosen" and must be refused.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateMissingModule()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveCreate'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 0]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field module not set","data":null}');
    }

    /**
     * Creating is gated on its own permission, checked before the form is even read.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateDeniedByAcl()
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveCreate'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 10]
            ),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * A database failure while creating must be reported as an error rather than surfaced as
     * an unhandled exception — this is the controller's own generic catch(Exception), distinct
     * from the ValidationException branch the missing-field tests above exercise.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateFailsWhenTheInsertQueryErrors()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'INSERT')) {
                throw ConstraintException::error('Unable to create the field');
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveCreate'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Unable to create the field","data":null}');
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
     * The form's guard applies to edit too: a blank name is refused before any query runs.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditMissingName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveEdit/100'],
                ['type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field name not set","data":null}');
    }

    /**
     * saveEditAction loads the definition by id before deciding how to persist it. Editing
     * an id that no longer exists must fail with "Field not found" instead of the service
     * silently updating/creating a row for a definition that was never there.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditNotFound()
    {
        // No mapper resolver registered for CustomFieldDefinition::class: getById() sees
        // 0 rows, exactly as it would for a definition deleted moments earlier.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveEdit/999'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 10]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Field not found","data":null}');
    }

    /**
     * When the submitted module differs from the stored one, saveEditAction takes the
     * changeModule() branch instead of a plain update — deleting the old row and recreating
     * it, since the repository's update() deliberately excludes 'moduleId' from its ->cols()
     * (an in-place UPDATE can never move a field between modules).
     *
     * Worth knowing while reading this: the recreated row gets a new id, and CustomFieldData's
     * definitionId is ON DELETE CASCADE (schemas/dbstructure.sql), so moving a field between
     * modules discards every value already stored in it. That is the design, not a regression,
     * and it is left alone here.
     *
     * This is the test that found the two defects fixed in this change: the row came back in the
     * module it started in, and the request answered a 500 afterwards. Both are asserted from the
     * outside — the module the INSERT actually bound, and the response the user gets.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditChangeModule()
    {
        $existing = $this->buildDefinition()->mutate(['id' => 100, 'moduleId' => 10]);

        $deleteRan = null;
        $insertRan = false;
        $updateRan = false;
        $insertedModuleId = null;

        $this->databaseQueryResolver = function (QueryData $queryData) use (
            $existing,
            &$deleteRan,
            &$insertRan,
            &$updateRan,
            &$insertedModuleId
        ): QueryResult {
            if ($queryData->getMapClassName() === CustomFieldDefinition::class) {
                return new QueryResult([$existing]);
            }

            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'DELETE')) {
                $deleteRan = $queryData->getQuery()->getBindValues();
            } elseif (str_starts_with($statement, 'INSERT')) {
                $insertRan = true;
                $insertedModuleId = $queryData->getQuery()->getBindValues()['moduleId'] ?? null;
            } elseif (str_starts_with($statement, 'UPDATE')) {
                $updateRan = true;
            }

            return new QueryResult([], 1, 999);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'customField/saveEdit/100'],
                ['name' => self::$faker->colorName(), 'type' => 1, 'module' => 20]
            )
        );

        IntegrationTestCase::runApp($container);

        self::assertSame(100, $deleteRan['id'] ?? null, 'Moving modules deletes the row being edited');
        self::assertTrue($insertRan, 'Moving modules must recreate the row');
        self::assertFalse($updateRan, 'Moving modules must not go through the in-place update path');

        // The whole point of the edit: the recreated row carries the module the user submitted.
        // It used to carry the pre-edit one, because the controller handed over the row it had
        // just read back instead of the validated posted values — so the move never happened.
        self::assertSame(20, $insertedModuleId, 'the field is recreated in the module it was moved to');

        // And the move reports as a plain success. It used to answer a 500 carrying a TypeError,
        // raised on the way out of a transaction that had already committed the delete and the
        // insert — so the data had moved and the user was told it had not.
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
