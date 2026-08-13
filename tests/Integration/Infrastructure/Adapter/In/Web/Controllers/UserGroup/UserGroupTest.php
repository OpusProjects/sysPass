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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserGroup;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\User\Models\UserGroup;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class UserGroupTest
 */
#[Group('integration')]
class UserGroupTest extends IntegrationTestCase
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/create'])
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
        $this->addDatabaseMapperResolver(UserGroup::class, new QueryResult([$this->buildUserGroup()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The view form is the edit form with its controls disabled.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerReadOnlyForm')]
    public function view()
    {
        $this->addDatabaseMapperResolver(UserGroup::class, new QueryResult([$this->buildUserGroup()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Editing a group id that no longer exists must not render a blank form:
     * UserGroupService::getById() raises NoSuchItemException when the lookup returns no
     * rows, and EditController's generic catch(Exception) has to turn that into an error
     * response instead of letting it bubble up as a fatal.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editNotFound()
    {
        // No addDatabaseMapperResolver() call: the harness default answers with an empty
        // result set, which is exactly the "no such group" case.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/edit/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Group not found","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Group deleted","data":null}');
    }

    /**
     * A group id that is already gone must be reported, not swallowed as success:
     * UserGroupService::delete() raises NoSuchItemException when the DELETE affects no rows,
     * which DeleteController's generic catch(Exception) turns into an error response.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteNotFound()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 0, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Group not found","data":null}');
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
                ['r' => 'userGroup/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Groups deleted","data":null}');
    }

    /**
     * If fewer rows are removed than ids were requested, deleteByIdBatch() raises a
     * ServiceException — a batch delete either fully succeeds or is reported as failed, it
     * never silently drops part of the selection.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialFailure()
    {
        // Two ids requested, only one actually removed.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'userGroup/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the groups","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/delete', 'items' => []])
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
                ['r' => 'userGroup/saveCreate'],
                ['name' => self::$faker->colorName(), 'description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Group added","data":null}');
    }

    /**
     * A group name that already exists must not silently overwrite anything: the
     * repository's pre-insert uniqueness check raises a DuplicatedItemException. It is
     * created inside UserGroupService::create()'s transactionAware() wrapper, which
     * re-throws it as a ServiceException carrying the same message — either way,
     * SaveCreateController's generic catch(Exception) has to surface it as an error instead
     * of inserting a second row.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateDuplicateName()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            // The uniqueness check: answering it with a row simulates an existing group
            // with the same name.
            if (str_contains($statement, 'UPPER(name)')) {
                return new QueryResult([new Simple(['id' => 999])]);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userGroup/saveCreate'],
                ['name' => self::$faker->colorName(), 'description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated group name","data":null}');
    }

    /**
     * A group is how accounts get shared, so the membership submitted alongside the group's
     * own fields has to actually reach the pivot table, not just the group row itself.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreatePersistsSelectedUsers()
    {
        $capturedUserIds = null;

        $this->databaseQueryResolver = function (QueryData $queryData) use (&$capturedUserIds): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'INSERT') && str_contains($statement, 'UserToUserGroup')) {
                $capturedUserIds = array_values($queryData->getQuery()->getBindValues());
            }

            return new QueryResult([], 1, 555);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userGroup/saveCreate'],
                [
                    'name' => self::$faker->colorName(),
                    'description' => self::$faker->sentence(),
                    'users' => [11, 22],
                ]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Group added","data":null}');

        self::assertNotNull($capturedUserIds, 'Selecting users on create must insert into the pivot table.');
        self::assertContains(11, $capturedUserIds);
        self::assertContains(22, $capturedUserIds);
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
                ['r' => 'userGroup/saveEdit/100'],
                ['name' => self::$faker->colorName(), 'description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Group updated","data":null}');
    }

    /**
     * A group name colliding with another group's must not silently rename onto it: the
     * repository's pre-update uniqueness check raises a DuplicatedItemException, wrapped by
     * UserGroupService::update()'s transactionAware() into a ServiceException with the same
     * message, which SaveEditController's generic catch(Exception) surfaces as an error.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditDuplicateName()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'UPPER(name)')) {
                return new QueryResult([new Simple(['id' => 999])]);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userGroup/saveEdit/100'],
                ['name' => self::$faker->colorName(), 'description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Duplicated group name","data":null}');
    }

    /**
     * Unlike UserProfileService::update(), UserGroupService::update() does not check how many
     * rows the UPDATE affected, so editing an id that no longer exists still reports success.
     * This pins down that actual (asymmetric) behaviour rather than assuming it matches the
     * profile side.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditSucceedsEvenWhenNoRowsAffected()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_starts_with($statement, 'UPDATE')) {
                return new QueryResult([], 0, 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userGroup/saveEdit/100'],
                ['name' => self::$faker->colorName(), 'description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Group updated","data":null}');
    }

    /**
     * A group without a name is rejected by the form rather than reaching the repository.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutName()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userGroup/saveCreate'],
                ['description' => self::$faker->sentence()]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A group name is needed","data":null}');
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
            UserGroup::class,
            QueryResult::withTotalNumRows([$this->buildUserGroup(), $this->buildUserGroup()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'userGroup/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function buildUserGroup(): UserGroup
    {
        return new UserGroup(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::$faker->colorName(),
                'description' => self::$faker->sentence(),
            ]
        );
    }

    /**
     * The group form carries the name and description fields, and the user picker.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $fields = $crawler->filterXPath('//form[@name="frmGroups"]//input|//form[@name="frmGroups"]//select')
                          ->extract(['name']);

        self::assertContains('name', $fields);
        self::assertContains('description', $fields);

        // An editable form offers the users picker; the read-only one does not.
        self::assertCount(1, $crawler->filterXPath('//select[@id="selUsers"]'));
    }

    /**
     * Viewing a group renders its members as plain text: the template only emits the users
     * picker when it is not a view, so the select must be absent.
     */
    private function outputCheckerReadOnlyForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);

        self::assertCount(0, $crawler->filterXPath('//select[@id="selUsers"]'));
        self::assertContains(
            'name',
            $crawler->filterXPath('//form[@name="frmGroups"]//input')->extract(['name'])
        );
    }

    /**
     * One row per group returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblGroups"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }
}
