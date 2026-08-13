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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out\Common\Repositories;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\Adapter\Out\Common\Repositories\Query;

/**
 * Class QueryTest
 *
 * Query wraps an already-built raw SQL string (produced outside Aura.SqlQuery) so it can be
 * handed to Database::runQuery() through the same Aura\SqlQuery\QueryInterface the rest of the
 * repositories use. These tests pin down that wrapper's own bookkeeping, independent of the
 * repository code that builds the strings it wraps.
 */
#[Group('unitary')]
class QueryTest extends TestCase
{
    /**
     * The statement and the initial bind values passed to buildForMySQL() must come back
     * unchanged — repositories rely on this to hand the same values through to PDO later.
     */
    public function testBuildForMySQLCreatesAQueryWithTheGivenStatementAndValues(): void
    {
        $query = Query::buildForMySQL('SELECT * FROM test WHERE id = :id', ['id' => 5]);

        self::assertSame('SELECT * FROM test WHERE id = :id', $query->getStatement());
        self::assertSame(['id' => 5], $query->getBindValues());
    }

    /**
     * Database::prepareAndRunQuery() logs and interpolates queries via string context
     * (e.g. sprintf/concatenation), so the object must stringify to the exact same SQL that
     * getStatement() reports — a mismatch here would make logged queries lie about what ran.
     */
    public function testToStringReturnsTheSameTextAsGetStatement(): void
    {
        $query = Query::buildForMySQL('DELETE FROM test WHERE id = :id', ['id' => 1]);

        self::assertSame($query->getStatement(), (string)$query);
        self::assertSame('DELETE FROM test WHERE id = :id', (string)$query);
    }

    /**
     * getQuoteNamePrefix()/getQuoteNameSuffix() delegate to an internal Aura\SqlQuery\Common\
     * Quoter, which quotes identifiers with a plain double quote by default — this documents
     * what a caller relying on QueryInterface's quoting contract actually gets from this class.
     */
    public function testGetQuoteNamePrefixAndSuffixDelegateToTheQuoter(): void
    {
        $query = Query::buildForMySQL('SELECT 1', []);

        self::assertSame('"', $query->getQuoteNamePrefix());
        self::assertSame('"', $query->getQuoteNameSuffix());
    }

    /**
     * bindValues() merges by iterating and re-binding each entry one at a time rather than via
     * array_merge(), specifically so that integer/positional placeholder keys are not
     * renumbered — array_merge() would collapse [0 => 'a', 2 => 'c'] + [1 => 'b'] down to
     * [0 => 'a', 1 => 'c', 2 => 'b'], silently corrupting question-mark-style bindings.
     */
    public function testBindValuesMergesWithoutRenumberingIntegerKeys(): void
    {
        $query = Query::buildForMySQL('SELECT ? , ?, ?, ?', [0 => 'a', 2 => 'c']);

        $query->bindValues([1 => 'b', 3 => 'd']);

        self::assertSame([0 => 'a', 2 => 'c', 1 => 'b', 3 => 'd'], $query->getBindValues());
    }

    /**
     * A key already present is overwritten rather than duplicated — the same "merges with
     * existing values" contract the interface promises for named placeholders.
     */
    public function testBindValuesOverwritesAnExistingKey(): void
    {
        $query = Query::buildForMySQL('SELECT * FROM test WHERE id = :id', ['id' => 1]);

        $query->bindValues(['id' => 2]);

        self::assertSame(['id' => 2], $query->getBindValues());
    }

    /**
     * bindValue()/bindValues() are chainable, the way repository code composes several binds in
     * one expression before handing the query off.
     */
    public function testBindValueAndBindValuesAreFluent(): void
    {
        $query = Query::buildForMySQL('SELECT 1', []);

        self::assertSame($query, $query->bindValue('a', 1));
        self::assertSame($query, $query->bindValues(['b' => 2]));
    }

    /**
     * A single bindValue() call both adds new placeholders and overwrites an existing one,
     * mirroring bindValues()'s merge semantics for the one-at-a-time API.
     */
    public function testBindValueAddsAndOverwritesValues(): void
    {
        $query = Query::buildForMySQL('SELECT * FROM test WHERE id = :id AND name = :name', ['id' => 1]);

        $query->bindValue('id', 2)->bindValue('name', 'foo');

        self::assertSame(['id' => 2, 'name' => 'foo'], $query->getBindValues());
    }

    /**
     * Other Aura query builders use resetFlags() to clear accumulated SQL flags (DISTINCT,
     * IGNORE, ...) before the statement is rebuilt. This class wraps an already-built raw
     * statement that has no such flags, so the override is a deliberate no-op — pinned down
     * here so a future implementation cannot silently start mutating the query on reset.
     */
    public function testResetFlagsLeavesTheQueryUnchanged(): void
    {
        $query = Query::buildForMySQL('SELECT 1 WHERE id = :id', ['id' => 1]);

        $query->resetFlags();

        self::assertSame('SELECT 1 WHERE id = :id', $query->getStatement());
        self::assertSame(['id' => 1], $query->getBindValues());
    }
}
