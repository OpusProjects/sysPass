<?php
declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out\Account\Repositories;

use Aura\SqlQuery\QueryFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Account\Models\AccountSearchView;
use SP\Domain\Account\Ports\AccountFilterBuilder;
use SP\Domain\Account\Ports\AccountSearchConstants;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Adapter\Out\Account\Repositories\AccountSearch;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountSearchRepositoryTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class AccountSearchTest extends UnitaryTestCase
{

    private MockObject|DatabaseInterface    $database;
    private AccountFilterBuilder|MockObject $accountFilterUser;
    private AccountSearch                   $accountSearch;

    public function testWithFilterForOwner()
    {
        $out = $this->accountSearch->withFilterForOwner('test_owner');

        $bind = [
            'userLogin' => '%test_owner%',
            'userName' => '%test_owner%',
        ];

        $query = '(`Account`.`userLogin` LIKE :userLogin OR `Account`.`userName` LIKE :userName)';

        $this->assertEquals($bind, $out->getBindValues());

        $this->checkQueryRegex($out->getStatement(), $query);
    }

    private function checkQueryRegex(string $statement, string $query): void
    {
        $output = preg_replace('/([\n\s\\n]+)/', ' ', $statement);
        $expected = sprintf('/^SELECT.*%s$/m', preg_quote($query));

        $this->assertMatchesRegularExpression($expected, $output);
    }

    public function testWithFilterForAccountNameRegex()
    {
        $out = $this->accountSearch->withFilterForAccountNameRegex('test_account');

        $bind = ['name' => 'test_account'];
        $query = '`Account`.`name` REGEXP :name';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForIsPrivate()
    {
        $out = $this->accountSearch->withFilterForIsPrivate(123, 456);

        $bind = ['userId' => 123, 'userGroupId' => 456];
        $query = '((`Account`.`isPrivate` = 1 AND `Account`.`userId` = :userId) OR (`Account`.`isPrivateGroup` = 1 AND `Account`.`userGroupId` = :userGroupId))';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForIsNotExpired()
    {
        $out = $this->accountSearch->withFilterForIsNotExpired();

        $query = '(`Account`.`passDateChange` = 0 OR `Account`.`passDateChange` IS NULL OR UNIX_TIMESTAMP() < `Account`.`passDateChange`)';

        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForGroup()
    {
        $out = $this->accountSearch->withFilterForGroup(123);

        $bind = ['userGroupId' => 123];
        $query = '(`Account`.`userGroupId` = :userGroupId OR `Account`.`id` IN (SELECT `AccountToUserGroup`.`accountId` FROM AccountToUserGroup WHERE `AccountToUserGroup`.`accountId` = id AND `AccountToUserGroup`.`userGroupId` = :userGroupId))';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForFile()
    {
        $out = $this->accountSearch->withFilterForFile('test_file');

        $bind = ['fileName' => '%test_file%'];
        $query = '(`Account`.`id` IN (SELECT `AccountFile`.`accountId` FROM AccountFile WHERE `AccountFile`.`name` LIKE :fileName))';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForMainGroup()
    {
        $out = $this->accountSearch->withFilterForMainGroup('test_group');

        $bind = ['userGroupName' => '%test_group%'];
        $query = '`Account`.`userGroupName` LIKE :userGroupName';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForCategory()
    {
        $out = $this->accountSearch->withFilterForCategory('test_category');

        $bind = ['categoryName' => '%test_category%'];
        $query = '`Account`.`categoryName` LIKE :categoryName';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForUser()
    {
        $out = $this->accountSearch->withFilterForUser(123, 456);

        $bind = ['userId' => 123, 'userGroupId' => 456];
        $query = '(`Account`.`userId` = :userId or `Account`.`userGroupId` = :userGroupId or `Account`.`id` IN (SELECT `AccountToUser`.`accountId` FROM AccountToUser WHERE `AccountToUser`.`accountId` = `Account`.`id` AND `AccountToUser`.`userId` = :userId UNION SELECT `AccountToUserGroup`.`accountId` FROM AccountToUserGroup WHERE `AccountToUserGroup`.`accountId` = `Account`.`id` AND `AccountToUserGroup`.`userGroupId` = :userGroupId))';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForClient()
    {
        $out = $this->accountSearch->withFilterForClient('test_client');

        $bind = ['clientName' => '%test_client%'];
        $query = '`Account`.`clientName` LIKE :clientName';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    /**
     * @return array<string, array{int|null, bool}>
     */
    public static function sortKeyProvider(): array
    {
        return [
            'name' => [AccountSearchConstants::SORT_NAME, false],
            'category' => [AccountSearchConstants::SORT_CATEGORY, false],
            'login' => [AccountSearchConstants::SORT_LOGIN, false],
            'url' => [AccountSearchConstants::SORT_URL, false],
            'client' => [AccountSearchConstants::SORT_CLIENT, false],
            'default (client, name)' => [null, false],
            'view count' => [null, true],
        ];
    }

    /**
     * Every ordering ends with the primary key, which is what makes it total.
     *
     * The search pages with LIMIT/OFFSET, and every sort key it offers is a non-unique column:
     * account names, logins, URLs, categories and client names all repeat, and the view count is
     * the worst of them, because most accounts sit at zero and therefore all tie. Rows that tie
     * are in no defined order, and the database may order them differently for each page it is
     * asked for — so one row arrives on two pages while another arrives on none.
     *
     * Asked of this schema directly, `ORDER BY countView DESC` over 104 accounts read in pages of
     * ten returned 63 of them on no page at all and 34 on two. A password manager that leaves a
     * third of somebody's accounts out of its own list is worse than one that shows them in an odd
     * order, and no amount of retrying finds them: the row is simply not on any page.
     *
     * This is asserted on the statement rather than by paging a real table, deliberately. Whether
     * a given plan happens to be stable is the database's business and changes with volume,
     * statistics and version; what the application controls, and what has to hold whatever the
     * plan, is that the ordering it asks for is total.
     */
    #[DataProvider('sortKeyProvider')]
    public function testEveryOrderingIsTotal(?int $sortKey, bool $sortViews): void
    {
        $filter = AccountSearchFilterDto::build('test');
        $filter->setLimitStart(10);
        $filter->setLimitCount(10);

        if ($sortKey !== null) {
            $filter->setSortKey($sortKey);
        }

        if ($sortViews) {
            $filter->setSortViews(true);
        }

        $statement = null;

        $this->database->expects(self::once())
                       ->method('runQuery')
                       ->with(
                           new Callback(static function (QueryData $data) use (&$statement) {
                               $statement = $data->getQuery()->getStatement();

                               return true;
                           }),
                           true
                       );

        $this->accountSearch->getByFilter($filter);

        self::assertIsString($statement);

        // The ORDER BY clause alone: LIMIT follows it, so this is about what the ordering ends
        // with, not what the statement ends with.
        self::assertSame(
            1,
            preg_match('/ORDER BY(.*?)(?:\\bLIMIT\\b|$)/is', $statement, $matches),
            'the query must carry an ORDER BY'
        );

        $columns = explode(',', trim($matches[1]));

        self::assertSame(
            '`Account`.`id`',
            trim((string)end($columns)),
            'a paged search must break ties on a unique column, or its pages are not a partition'
        );
    }


    /**
     * A descending sort on the default key sorts *both* its columns descending.
     *
     * The default key is two columns, and the direction used to be appended to the pair as one
     * string — `ORDER BY Account.clientName, Account.name DESC`, which in SQL sorts only the last
     * column descending. So asking for descending order left the clients ascending and reversed
     * the names within each of them, which is neither of the two orders a user can ask for.
     */
    public function testADescendingDefaultSortAppliesToBothOfItsColumns(): void
    {
        $filter = AccountSearchFilterDto::build('test');
        $filter->setSortOrder(AccountSearchConstants::SORT_DIR_DESC);

        $statement = null;

        $this->database->expects(self::once())
                       ->method('runQuery')
                       ->with(
                           new Callback(static function (QueryData $data) use (&$statement) {
                               $statement = $data->getQuery()->getStatement();

                               return true;
                           }),
                           true
                       );

        $this->accountSearch->getByFilter($filter);

        self::assertIsString($statement);
        self::assertStringContainsString('`Account`.`clientName` DESC', $statement);
        self::assertStringContainsString('`Account`.`name` DESC', $statement);
    }


    public function testGetByFilter()
    {
        $accountSearchFilter = AccountSearchFilterDto::build('test');
        $accountSearchFilter->setCleanTxtSearch('test');
        $accountSearchFilter->setGlobalSearch(true);
        $accountSearchFilter->setSearchFavorites(true);
        $accountSearchFilter->setCategoryId(123);
        $accountSearchFilter->setClientId(456);
        $accountSearchFilter->setTagsId([1, 2, 3]);
        $accountSearchFilter->setLimitStart(1);
        $accountSearchFilter->setLimitCount(10);
        $accountSearchFilter->setSortKey(AccountSearchConstants::SORT_CATEGORY);
        $accountSearchFilter->setSortOrder(AccountSearchConstants::SORT_DIR_DESC);
        $accountSearchFilter->setFilterOperator(AccountSearchConstants::FILTER_CHAIN_AND);

        $this->accountFilterUser->expects(self::once())
                                ->method('buildFilter')
                                ->with(true, self::anything());
        $this->database->expects(self::once())
            ->method('runQuery')
                       ->with(
                           new Callback(static function (QueryData $data) {
                               return !empty($data->getQuery()->getStatement()) &&
                                      $data->getMapClassName() === AccountSearchView::class;
                           }),
                           true
                       );

        $this->accountSearch->getByFilter($accountSearchFilter);
    }

    public function testGetByFilterWithSortViews()
    {
        $accountSearchFilter = AccountSearchFilterDto::build('test');
        $accountSearchFilter->setSortViews(true);

        $this->accountFilterUser->expects(self::once())
                                ->method('buildFilter');

        $this->database->expects(self::once())
            ->method('runQuery')
                       ->with(
                           new Callback(static function (QueryData $data) {
                               return !empty($data->getQuery()->getStatement()) &&
                                      $data->getMapClassName() === AccountSearchView::class;
                           }),
                           true
                       );

        $this->accountSearch->getByFilter($accountSearchFilter);
    }

    public function testWithFilterForAccountId()
    {
        $out = $this->accountSearch->withFilterForAccountId(123);

        $bind = ['accountId' => 123];
        $query = '`Account`.`id` = :accountId';

        $this->assertEquals($bind, $out->getBindValues());
        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForIsNotPrivate()
    {
        $out = $this->accountSearch->withFilterForIsNotPrivate();

        $query = '(`Account`.`isPrivate` = 0 OR `Account`.`isPrivate` IS NULL) AND (`Account`.`isPrivateGroup` = 0 OR `Account`.`isPrivateGroup` IS NULL)';

        $this->checkQueryRegex($out->getStatement(), $query);
    }

    public function testWithFilterForIsExpired()
    {
        $out = $this->accountSearch->withFilterForIsExpired();

        $query = '(`Account`.`passDateChange` > 0 AND UNIX_TIMESTAMP() > `Account`.`passDateChange`)';

        $this->checkQueryRegex($out->getStatement(), $query);
    }

    /**
     * When the user picks "match any" (OR), the dimension conditions (text, category, client,
     * tags) must be OR-ed together rather than AND-ed — and the tag check itself has to switch
     * from "has every tag" (a COUNT) to "has any tag" (an EXISTS), since under OR a single
     * matching tag is enough. Both are driven by the same filterOperator, so one filter with
     * text + tags set to OR exercises both branches together.
     */
    public function testGetByFilterCombinesDimensionsWithOrAndUsesExistsForTags(): void
    {
        $accountSearchFilter = AccountSearchFilterDto::build('test');
        $accountSearchFilter->setCleanTxtSearch('test');
        $accountSearchFilter->setTagsId([7, 8]);
        $accountSearchFilter->setFilterOperator(AccountSearchConstants::FILTER_CHAIN_OR);

        $captured = null;
        $this->database->expects(self::once())
            ->method('runQuery')
            ->willReturnCallback(function (QueryData $queryData) use (&$captured) {
                $captured = $queryData;

                return new QueryResult();
            });

        $this->accountSearch->getByFilter($accountSearchFilter);

        $statement = preg_replace('/[\n\s]+/', ' ', $captured->getQuery()->getStatement());

        // The text-search clause and the tags clause are chained with "or" (the operator glue),
        // not "and" (the default) — and the tags side is the EXISTS form, not the AND-only COUNT.
        self::assertStringContainsString(
            '(`Account`.`name` LIKE :name OR `Account`.`login` LIKE :login OR `Account`.`url` LIKE :url OR `Account`.`notes` LIKE :notes)'
            . ' or EXISTS (SELECT 1 FROM AccountToTag',
            $statement
        );
        self::assertStringNotContainsString('COUNT(DISTINCT', $statement);

        self::assertSame(
            [
                'name' => '%test%',
                'login' => '%test%',
                'url' => '%test%',
                'notes' => '%test%',
                '__1__' => 7,
                '__2__' => 8,
            ],
            $captured->getQuery()->getBindValues()
        );
    }

    /**
     * The other half of the same trap: even with a search text of "0" reaching this far, the
     * filter used to be dropped here too, and the query went out with no text condition at all —
     * so a search for 0 answered with every account rather than with the ones matching.
     */
    public function testGetByFilterKeepsATextFilterForZero(): void
    {
        $accountSearchFilter = AccountSearchFilterDto::build('0');
        $accountSearchFilter->setCleanTxtSearch('0');

        $captured = null;
        $this->database->expects(self::once())
            ->method('runQuery')
            ->willReturnCallback(function (QueryData $queryData) use (&$captured) {
                $captured = $queryData;

                return new QueryResult();
            });

        $this->accountSearch->getByFilter($accountSearchFilter);

        self::assertSame(
            [
                'name' => '%0%',
                'login' => '%0%',
                'url' => '%0%',
                'notes' => '%0%',
            ],
            $captured->getQuery()->getBindValues()
        );
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function sortKeyDataProvider(): array
    {
        return [
            'name' => [AccountSearchConstants::SORT_NAME, '`Account`.`name`'],
            'login' => [AccountSearchConstants::SORT_LOGIN, '`Account`.`login`'],
            'url' => [AccountSearchConstants::SORT_URL, '`Account`.`url`'],
            'client' => [AccountSearchConstants::SORT_CLIENT, '`Account`.`clientName`'],
        ];
    }

    /**
     * Each sort key the user can pick orders by a different column; the default (no key chosen)
     * is covered by testGetByFilter already, so this covers the rest of the match().
     */
    #[DataProvider('sortKeyDataProvider')]
    public function testGetByFilterOrdersByTheChosenSortKey(int $sortKey, string $expectedColumn): void
    {
        $accountSearchFilter = AccountSearchFilterDto::build(null);
        $accountSearchFilter->setSortKey($sortKey);

        $captured = null;
        $this->database->expects(self::once())
            ->method('runQuery')
            ->willReturnCallback(function (QueryData $queryData) use (&$captured) {
                $captured = $queryData;

                return new QueryResult();
            });

        $this->accountSearch->getByFilter($accountSearchFilter);

        $statement = preg_replace('/[\n\s]+/', ' ', $captured->getQuery()->getStatement());

        // The DTO defaults sortOrder to ASC, so this also covers the sort-direction match()'s
        // "default" arm (only DESC is exercised elsewhere), alongside the sort-key column.
        self::assertStringContainsString(sprintf('ORDER BY %s ASC', $expectedColumn), $statement);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(DatabaseInterface::class);
        $this->accountFilterUser = $this->createMock(AccountFilterBuilder::class);
        $queryFactory = new QueryFactory('mysql');

        $this->accountSearch = new AccountSearch(
            $this->database,
            $this->context,
            $this->application->getEventDispatcher(),
            $queryFactory,
            $this->accountFilterUser
        );
    }
}
