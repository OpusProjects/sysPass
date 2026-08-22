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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out;

use Aura\SqlQuery\QueryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use ReflectionClass;
use ReflectionMethod;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Every paged search orders by something unique, so its pages are a partition of the results.
 *
 * A search that pages with LIMIT/OFFSET over an ORDER BY that does not determine a unique order
 * lets the database return tied rows differently for each page it is asked for: one row then
 * arrives on two pages while another arrives on none, and retrying never finds it, because the row
 * is on no page at all. Asked of this schema directly — `ORDER BY countView DESC` over 104 accounts
 * read in pages of ten — 63 accounts appeared on no page and 34 on two.
 *
 * Every table here has `id` for a primary key, so the rule is one rule with no exceptions to keep
 * a list of: the last thing a paged search orders by is the primary key. Where the leading column
 * is already unique — `UserGroup.name`, `UserProfile.name`, `Plugin.name` — it costs nothing and
 * survives somebody dropping that index later.
 *
 * Asserted on the statement rather than by paging a real table on purpose: whether a given plan
 * happens to be stable is the database's business and moves with volume, statistics and version.
 * What the application controls, and what has to hold whatever the plan, is the order it asks for.
 */
#[Group('unitary')]
class PagedSearchesAreTotallyOrderedTest extends UnitaryTestCase
{
    /**
     * Every repository whose `search()` pages with LIMIT/OFFSET.
     *
     * `AccountSearch` is absent because its ordering is chosen per request rather than fixed here,
     * and `AccountSearchTest::testEveryOrderingIsTotal` covers each of its sort keys instead.
     *
     * @return array<string, array{class-string}>
     */
    public static function pagedRepositoryProvider(): array
    {
        $classes = [
            \SP\Infrastructure\Adapter\Out\Account\Repositories\Account::class,
            \SP\Infrastructure\Adapter\Out\Account\Repositories\AccountFile::class,
            \SP\Infrastructure\Adapter\Out\Account\Repositories\AccountHistory::class,
            \SP\Infrastructure\Adapter\Out\Account\Repositories\PublicLink::class,
            \SP\Infrastructure\Adapter\Out\Auth\Repositories\AuthToken::class,
            \SP\Infrastructure\Adapter\Out\Category\Repositories\Category::class,
            \SP\Infrastructure\Adapter\Out\Client\Repositories\Client::class,
            \SP\Infrastructure\Adapter\Out\CustomField\Repositories\CustomFieldDefinition::class,
            \SP\Infrastructure\Adapter\Out\ItemPreset\Repositories\ItemPreset::class,
            \SP\Infrastructure\Adapter\Out\Plugin\Repositories\Plugin::class,
            \SP\Infrastructure\Adapter\Out\Security\Repositories\Eventlog::class,
            \SP\Infrastructure\Adapter\Out\Security\Repositories\Track::class,
            \SP\Infrastructure\Adapter\Out\Tag\Repositories\Tag::class,
            \SP\Infrastructure\Adapter\Out\User\Repositories\User::class,
            \SP\Infrastructure\Adapter\Out\User\Repositories\UserGroup::class,
            \SP\Infrastructure\Adapter\Out\User\Repositories\UserProfile::class,
        ];

        $cases = [];

        foreach ($classes as $class) {
            $cases[substr((string)strrchr($class, '\\'), 1)] = [$class];
        }

        return $cases;
    }

    /**
     * @param class-string $repositoryClass
     *
     * @throws Exception
     */
    #[Test]
    #[DataProvider('pagedRepositoryProvider')]
    public function aPagedSearchOrdersByThePrimaryKeyLast(string $repositoryClass): void
    {
        $statement = $this->captureSearchStatement($repositoryClass);

        self::assertStringContainsStringIgnoringCase(
            'LIMIT',
            $statement,
            'this test is only meaningful for a search that pages'
        );

        self::assertSame(
            'id',
            self::lastOrderedColumn($statement),
            $repositoryClass . ': a paged search must order by the primary key last, or its pages'
            . ' are not a partition of the results'
        );
    }

    /**
     * @param class-string $repositoryClass
     *
     * @throws Exception
     */
    private function captureSearchStatement(string $repositoryClass): string
    {
        $statement = null;

        $database = $this->createStub(DatabaseInterface::class);
        $database->method('runQuery')->willReturnCallback(
            static function (QueryData $queryData) use (&$statement) {
                $statement = $queryData->getQuery()->getStatement();

                return new \SP\Domain\Common\Dtos\QueryResult([]);
            }
        );

        // Two of these do not take the shared BaseRepository constructor or the bare search()
        // signature; building them by reflection keeps the list above a list of repositories
        // rather than a list of ways to construct one.
        $repository = self::build($repositoryClass, $database, $this->context, $this->application->getEventDispatcher());

        $arguments = [new ItemSearchDto(null, 10, 10)];

        if ((new ReflectionMethod($repositoryClass, 'search'))->getNumberOfRequiredParameters() > 1) {
            // Track::search() also takes the window it counts attempts within.
            $arguments[] = time();
        }

        $repository->search(...$arguments);

        self::assertIsString($statement, $repositoryClass . ': no query was run');

        return $statement;
    }

    /**
     * The bare column name the ORDER BY ends with, without its table qualifier or direction —
     * `AccountHistory` orders by its own key as `Account.id`, having aliased the table, and
     * `Eventlog` orders by `id DESC`. Both are the primary key and both are total.
     */
    private static function lastOrderedColumn(string $statement): string
    {
        self::assertSame(
            1,
            preg_match('/ORDER BY(.*?)(?:\bLIMIT\b|$)/is', $statement, $matches),
            'the query must carry an ORDER BY'
        );

        $columns = explode(',', trim($matches[1]));
        $last = trim((string)end($columns));

        // strip a trailing ASC/DESC, then any table qualifier, then the identifier quoting
        $last = (string)preg_replace('/\s+(ASC|DESC)$/i', '', $last);
        $last = substr((string)strrchr($last, '.'), 1) ?: $last;

        return trim($last, '`');
    }

    /**
     * Most repositories take BaseRepository's four arguments in its order; `Account` declares its
     * own constructor with the query factory and the dispatcher the other way round and one extra
     * dependency. Reflection settles it rather than a special case per class.
     *
     * @param class-string $repositoryClass
     *
     * @throws Exception
     */
    private static function build(
        string $repositoryClass,
        DatabaseInterface $database,
        Context $context,
        EventDispatcherInterface $eventDispatcher
    ): object {
        $arguments = [];

        foreach ((new ReflectionClass($repositoryClass))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string)$parameter->getType();

            $arguments[] = match (true) {
                is_a($type, DatabaseInterface::class, true) => $database,
                is_a($type, Context::class, true) => $context,
                is_a($type, EventDispatcherInterface::class, true) => $eventDispatcher,
                is_a($type, QueryFactory::class, true) => new QueryFactory('mysql'),
                default => (new self('build'))->createStub($type),
            };
        }

        return new $repositoryClass(...$arguments);
    }
}
