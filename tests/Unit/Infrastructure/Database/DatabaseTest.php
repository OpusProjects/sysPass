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

namespace SP\Tests\Unit\Infrastructure\Database;

use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\QueryInterface;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use RuntimeException;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\Database\Ports\QueryDataInterface;
use SP\Infrastructure\Database\Database;
use SP\Domain\Database\DbStorageDriver;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class DatabaseTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DatabaseTest extends UnitaryTestCase
{

    private MockObject|DbStorageHandler $dbStorageHandler;
    private Database                    $database;

    public static function bufferedDataProvider(): array
    {
        return [
            [true],
            [false]
        ];
    }

    /**
     * @throws Exception
     */
    public function testBeginTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(false);

        $pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        self::assertTrue($this->database->beginTransaction());
    }

    /**
     * @throws Exception
     */
    public function testBeginTransactionWithExistingTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $pdo->expects($this->never())
            ->method('beginTransaction');

        self::assertTrue($this->database->beginTransaction());
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithMappedClass()
    {
        list($pdoStatement, $query) = $this->checkPrepare();

        $pdoStatement->expects($this->once())
                     ->method('fetchAll')
                     ->with(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Simple::class);

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once(1))
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->once(1))
                  ->method('getMapClassName')
                  ->willReturn(Simple::class);

        $this->database->runQuery($queryData);
    }

    /**
     * @param string $queryType
     * @param bool $useValues
     * @param int $times
     * @param array $prepareOptions
     * @return array
     * @throws Exception
     */
    private function checkPrepare(
        string $queryType = SelectInterface::class,
        bool   $useValues = true,
        int    $times = 1,
        array  $prepareOptions = []
    ): array {
        $pdo = $this->createMock(PDO::class);
        $pdoStatement = $this->createMock(PDOStatement::class);
        $query = $this->createMock($queryType);

        $query->expects($this->atLeast($times))
              ->method('getStatement')
              ->willReturn('SELECT * FROM test WHERE col1 = :a AND col2 = :b AND col3 = :c');

        if ($useValues) {
            $query->expects($this->exactly($times))
                  ->method('getBindValues')
                  ->willReturn(['a' => 'test', 'b' => 100, 'c' => false]);

            $counter = new InvokedCount(3 * $times);
            $pdoStatement->expects($counter)
                         ->method('bindValue')
                         ->with(
                             self::callback(static function (string $arg) use ($counter) {
                                 return match ($counter->numberOfInvocations()) {
                                     1, 4 => $arg === 'a',
                                     2, 5 => $arg === 'b',
                                     3, 6 => $arg === 'c',
                                 };
                             }),
                             self::callback(static function (mixed $arg) use ($counter) {
                                 return match ($counter->numberOfInvocations()) {
                                     1, 4 => $arg === 'test',
                                     2, 5 => $arg === 100,
                                     3, 6 => $arg === false,
                                 };
                             }),
                             self::callback(static function (int $arg) use ($counter) {
                                 return match ($counter->numberOfInvocations()) {
                                     1, 4 => $arg === PDO::PARAM_STR,
                                     2, 5 => $arg === PDO::PARAM_INT,
                                     3, 6 => $arg === PDO::PARAM_BOOL,
                                 };
                             }),
                         );
        } else {
            $query->expects($this->exactly($times))
                  ->method('getBindValues')
                  ->willReturn([]);

            $pdoStatement->expects($this->never())
                         ->method('bindValue');
        }

        $pdo->expects($this->exactly($times))
            ->method('prepare')
            ->with('SELECT * FROM test WHERE col1 = :a AND col2 = :b AND col3 = :c', $prepareOptions)
            ->willReturn($pdoStatement);

        $pdoStatement->expects($this->exactly($times))
                     ->method('execute');

        $this->dbStorageHandler
            ->expects($this->exactly($times))
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->exactly($times))
            ->method('lastInsertId')
            ->willReturn('123');

        return array($pdoStatement, $query);
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithNoMappedClass()
    {
        list($pdoStatement, $query) = $this->checkPrepare();

        $pdoStatement->expects($this->once())
                     ->method('fetchAll')
                     ->with(PDO::FETCH_DEFAULT);

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->once())
                  ->method('getMapClassName');

        $this->database->runQuery($queryData);
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithMappedClassAndFullCount()
    {
        /** @var QueryInterface|MockObject $query */
        /** @var PDO|MockObject $pdoStatement */
        list($pdoStatement, $query) = $this->checkPrepare(times: 2);

        $pdoStatement->expects($this->once())
                     ->method('fetchAll')
                     ->with(PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, Simple::class);

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->once())
                  ->method('getMapClassName')
                  ->willReturn(Simple::class);

        $queryData->expects($this->once())
                  ->method('getQueryCount')
                  ->willReturn($query);

        $pdoStatement->expects($this->once())
                     ->method('fetchColumn')
                     ->willReturn(10);

        $this->database->runQuery($queryData, true);
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithNoSelect()
    {
        list($pdoStatement, $query) = $this->checkPrepare(QueryInterface::class);

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->never())
                  ->method('getMapClassName');

        $pdoStatement->expects($this->never())
                     ->method('fetchAll');

        $pdoStatement->expects($this->once())
                     ->method('rowCount')
                     ->willReturn(10);

        $out = $this->database->runQuery($queryData);

        $this->assertEquals(10, $out->getAffectedNumRows());
        $this->assertEquals('123', $out->getLastId());
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithNoValues()
    {
        list($pdoStatement, $query) = $this->checkPrepare(QueryInterface::class, false);

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->never())
                  ->method('getMapClassName');

        $pdoStatement->expects($this->never())
                     ->method('fetchAll');

        $pdoStatement->expects($this->once())
                     ->method('rowCount')
                     ->willReturn(10);

        $out = $this->database->runQuery($queryData);

        $this->assertEquals(10, $out->getAffectedNumRows());
        $this->assertEquals('123', $out->getLastId());
    }

    /**
     * An array-bound param whose name is a strict prefix of another bind in the same
     * query (":id" vs ":idOwner") must not corrupt the longer token when the array is
     * expanded into placeholders.
     *
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testRunQueryWithArrayBindDoesNotCorruptPrefixedParam()
    {
        $pdo = $this->createMock(PDO::class);
        $pdoStatement = $this->createMock(PDOStatement::class);
        $query = $this->createMock(QueryInterface::class);

        $query->expects($this->atLeastOnce())
              ->method('getStatement')
              ->willReturn('SELECT * FROM test WHERE id IN (:id) AND idOwner = :idOwner');

        $query->expects($this->once())
              ->method('getBindValues')
              ->willReturn(['id' => [1, 2], 'idOwner' => 5]);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM test WHERE id IN (:id_0, :id_1) AND idOwner = :idOwner', [])
            ->willReturn($pdoStatement);

        $counter = new InvokedCount(3);
        $pdoStatement->expects($counter)
                     ->method('bindValue')
                     ->with(
                         self::callback(static function (string $arg) use ($counter) {
                             return match ($counter->numberOfInvocations()) {
                                 1 => $arg === 'id_0',
                                 2 => $arg === 'id_1',
                                 3 => $arg === 'idOwner',
                             };
                         }),
                         self::callback(static function (mixed $arg) use ($counter) {
                             return match ($counter->numberOfInvocations()) {
                                 1 => $arg === 1,
                                 2 => $arg === 2,
                                 3 => $arg === 5,
                             };
                         }),
                         self::callback(static fn(int $arg) => $arg === PDO::PARAM_INT),
                     );

        $pdoStatement->expects($this->once())
                     ->method('execute');

        $pdoStatement->expects($this->never())
                     ->method('fetchAll');

        $pdoStatement->expects($this->once())
                     ->method('rowCount')
                     ->willReturn(2);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->never())
                  ->method('getMapClassName');

        $out = $this->database->runQuery($queryData);

        $this->assertEquals(2, $out->getAffectedNumRows());
    }

    /**
     * @throws Exception
     */
    public function testEndTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $pdo->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        self::assertTrue($this->database->endTransaction());
    }

    /**
     * @throws Exception
     */
    public function testEndTransactionWithNoExistingTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(false);

        $pdo->expects($this->never())
            ->method('commit');

        self::assertFalse($this->database->endTransaction());
    }

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     */
    #[DataProvider('bufferedDataProvider')]
    public function testDoFetchWithOptions(bool $buffered)
    {
        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getDriver')
            ->willReturn(DbStorageDriver::mysql);

        /** @var PDOStatement|MockObject $pdoStatement */
        /** @var QueryInterface|MockObject $query */
        list($pdoStatement, $query) = $this->checkPrepare(
            QueryInterface::class,
            false,
            1,
            [\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => $buffered]
        );

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $pdoStatement->expects($this->exactly(2))
                     ->method('fetch')
                     ->with(PDO::FETCH_DEFAULT)
                     ->willReturn(['a', 1, false], false);

        $out = $this->database->doFetchWithOptions(queryData: $queryData, buffered: $buffered);

        foreach ($out as $row) {
            $this->assertEquals($row, ['a', 1, false]);
        }
    }

    /**
     * @throws Exception
     */
    public function testRollbackTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $pdo->expects($this->once())
            ->method('rollBack')
            ->willReturn(true);

        self::assertTrue($this->database->rollbackTransaction());
    }

    /**
     * @throws Exception
     */
    public function testRollbackTransactionWithNoTransaction()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(false);

        $pdo->expects($this->never())
            ->method('rollBack');

        self::assertFalse($this->database->rollbackTransaction());
    }

    /**
     * @throws Exception
     */
    public function testRollbackTransactionWithNoRollback()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $pdo->expects($this->once())
            ->method('rollBack')
            ->willReturn(false);

        self::assertFalse($this->database->rollbackTransaction());
    }

    /**
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryRaw()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('exec')
            ->with('a_query')
            ->willReturn(1);

        $this->database->runQueryRaw('a_query');
    }

    /**
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryRawWithException()
    {
        $pdo = $this->createMock(PDO::class);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('exec')
            ->with('a_query')
            ->willReturn(false);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Error executing the query');

        $this->database->runQueryRaw('a_query');
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithEmptyQueryException()
    {
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);
        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('an_error');

        $this->database->runQuery($queryData);
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithConnectionException()
    {
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);
        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willThrowException(new RuntimeException('test'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Error while doing the query');

        $this->database->runQuery($queryData);
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithPrepareException()
    {
        $pdo = $this->createStub(PDO::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->method('prepare')
            ->willThrowException(new RuntimeException('test'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Error while doing the query');

        $this->database->runQuery($queryData);
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithExecuteException()
    {
        $pdo = $this->createStub(PDO::class);
        $pdoStatement = $this->createStub(PDOStatement::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->method('prepare')
            ->willReturn($pdoStatement);

        $pdoStatement->method('execute')
                     ->willThrowException(new RuntimeException('test'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Error while doing the query');

        $this->database->runQuery($queryData);
    }

    /**
     * The driver text must not be lost when it stops being the message — it moves to the hint,
     * which is what the API surfaces as error.detail.
     *
     * @throws Exception
     */
    public function testRunQueryKeepsDriverDetailAsHint()
    {
        $pdo = $this->createStub(PDO::class);
        $pdoStatement = $this->createStub(PDOStatement::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->method('prepare')
            ->willReturn($pdoStatement);

        $driverMessage = "SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column 'userId' at row 1";

        $pdoStatement->method('execute')
                     ->willThrowException(new RuntimeException($driverMessage, 22003));

        try {
            $this->database->runQuery($queryData);
            self::fail('Expected a QueryException');
        } catch (QueryException $e) {
            $this->assertSame('Error while doing the query', $e->getMessage());
            $this->assertSame($driverMessage, $e->getHint());
            $this->assertSame(22003, $e->getCode());
        }
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithConstraintException()
    {
        $pdo = $this->createStub(PDO::class);
        $pdoStatement = $this->createStub(PDOStatement::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->method('prepare')
            ->willReturn($pdoStatement);

        $pdoStatement->method('execute')
                     ->willThrowException(new RuntimeException('test', 23000));

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('Integrity constraint');
        $this->expectExceptionCode(23000);

        $this->database->runQuery($queryData);
    }

    /**
     * MySQL error 1062 (duplicate key) gets a message an operator can act on instead of the raw
     * "Integrity constraint" fallback used for driver codes that aren't specifically handled.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithConstraintExceptionForDuplicateEntry()
    {
        $this->expectExceptionMessage('Duplicate entry');

        $this->runQueryWithDriverConstraintCode(1062);
    }

    /**
     * MySQL error 1451 (row referenced by a foreign key elsewhere) is reported as "the record is
     * in use" rather than the generic constraint message, so a delete failure is self-explanatory.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithConstraintExceptionForRecordInUse()
    {
        $this->expectExceptionMessage('The record is in use');

        $this->runQueryWithDriverConstraintCode(1451);
    }

    /**
     * MySQL error 1452 (foreign key target missing) is reported as "referenced record not
     * found" rather than the generic constraint message, distinguishing it from 1451 above.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithConstraintExceptionForReferencedRecordNotFound()
    {
        $this->expectExceptionMessage('Referenced record not found');

        $this->runQueryWithDriverConstraintCode(1452);
    }

    /**
     * A value longer than its column says which field was too long.
     *
     * No form validates a length and `Filter::getString()` does not truncate, so the column is the
     * only limit there is, and `STRICT_TRANS_TABLES` makes the server refuse the row rather than
     * shorten the value. That refusal used to fall through to "Error while doing the query" —
     * which does not say that length was the problem, let alone which field, and is what somebody
     * saw for typing one character too many into a name.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithATooLongValueNamesTheColumn()
    {
        $this->expectExceptionMessage('The value for "name" is too long');

        $this->runQueryWithTooLongValue("Data too long for column 'name' at row 1");
    }

    /**
     * A driver that phrases it differently still reports it as a length problem rather than
     * guessing at a column name that isn't in the message.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithATooLongValueAndNoColumnInTheDriverText()
    {
        $this->expectExceptionMessage('The value is too long');

        $this->runQueryWithTooLongValue('String data, right truncated');
    }

    /**
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    private function runQueryWithTooLongValue(string $driverDetail): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdoStatement = $this->createStub(PDOStatement::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')->willReturn('an_error');
        $query->method('getStatement')->willReturn('test_query');
        $queryData->method('getQuery')->willReturn($query);

        $this->dbStorageHandler->method('getConnection')->willReturn($pdo);
        $pdo->method('prepare')->willReturn($pdoStatement);

        $pdoException = new PDOException('SQLSTATE[22001]', 22001);
        $pdoException->errorInfo = ['22001', 1406, $driverDetail];

        $pdoStatement->method('execute')->willThrowException($pdoException);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode(22001);

        $this->database->runQuery($queryData);
    }

    /**
     * Drives runQuery() into the ConstraintException branch with a real PDOException carrying
     * the given MySQL driver error code in errorInfo[1], the way the PDO MySQL driver actually
     * reports it (a plain exception code of 23000 alone never carries the specific 1062/1451/1452
     * distinction — only errorInfo does).
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    private function runQueryWithDriverConstraintCode(int $driverCode): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdoStatement = $this->createStub(PDOStatement::class);
        $query = $this->createStub(QueryInterface::class);
        $queryData = $this->createStub(QueryDataInterface::class);

        $queryData->method('getOnErrorMessage')
                  ->willReturn('an_error');

        $query->method('getStatement')
              ->willReturn('test_query');

        $queryData->method('getQuery')
                  ->willReturn($query);

        $this->dbStorageHandler
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->method('prepare')
            ->willReturn($pdoStatement);

        $pdoException = new PDOException('constraint violated', 23000);
        $pdoException->errorInfo = ['23000', $driverCode, 'driver detail'];

        $pdoStatement->method('execute')
                     ->willThrowException($pdoException);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionCode(23000);

        $this->database->runQuery($queryData);
    }

    /**
     * The same named parameter appearing more than once in a query (e.g. "WHERE a = :x OR
     * b = :x") is something PDO's native prepared statements reject outright — binding the same
     * name twice throws. The second and later occurrences are renamed to :x__2, :x__3, ... and
     * each copy is bound to the same value, so the caller can write the natural SQL without
     * worrying about repeats.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryWithRepeatedNamedParameterIsDeduplicated()
    {
        $pdo = $this->createMock(PDO::class);
        $pdoStatement = $this->createMock(PDOStatement::class);
        $query = $this->createMock(QueryInterface::class);

        $query->expects($this->atLeastOnce())
              ->method('getStatement')
              ->willReturn('SELECT * FROM test WHERE a = :x OR b = :x');

        $query->expects($this->once())
              ->method('getBindValues')
              ->willReturn(['x' => 5]);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM test WHERE a = :x OR b = :x__2', [])
            ->willReturn($pdoStatement);

        $counter = new InvokedCount(2);
        $pdoStatement->expects($counter)
                     ->method('bindValue')
                     ->with(
                         self::callback(static function (string $arg) use ($counter) {
                             return match ($counter->numberOfInvocations()) {
                                 1 => $arg === 'x',
                                 2 => $arg === 'x__2',
                             };
                         }),
                         self::callback(static fn(mixed $arg) => $arg === 5),
                         self::callback(static fn(int $arg) => $arg === PDO::PARAM_INT),
                     );

        $pdoStatement->expects($this->once())
                     ->method('execute');

        $pdoStatement->expects($this->never())
                     ->method('fetchAll');

        $pdoStatement->expects($this->once())
                     ->method('rowCount')
                     ->willReturn(1);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->never())
                  ->method('getMapClassName');

        $out = $this->database->runQuery($queryData);

        $this->assertEquals(1, $out->getAffectedNumRows());
    }

    /**
     * A bound value that has no matching :placeholder left in the final SQL text (e.g. a
     * repository builds a WHERE clause conditionally and ends up passing a now-unused key) is
     * dropped before reaching PDO. Binding a parameter PDO can't find in the statement throws
     * "SQLSTATE[HY093]: Invalid parameter number", which would turn an unrelated, harmless extra
     * value into a hard query failure.
     *
     * @throws ConstraintException
     * @throws Exception
     * @throws QueryException
     */
    public function testRunQueryDropsABoundValueWithNoMatchingPlaceholder()
    {
        $pdo = $this->createMock(PDO::class);
        $pdoStatement = $this->createMock(PDOStatement::class);
        $query = $this->createMock(QueryInterface::class);

        $query->expects($this->atLeastOnce())
              ->method('getStatement')
              ->willReturn('SELECT * FROM test WHERE a = :a');

        $query->expects($this->once())
              ->method('getBindValues')
              ->willReturn(['a' => 1, 'unused' => 2]);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM test WHERE a = :a', [])
            ->willReturn($pdoStatement);

        $pdoStatement->expects($this->once())
                     ->method('bindValue')
                     ->with('a', 1, PDO::PARAM_INT);

        $pdoStatement->expects($this->once())
                     ->method('execute');

        $pdoStatement->expects($this->never())
                     ->method('fetchAll');

        $pdoStatement->expects($this->once())
                     ->method('rowCount')
                     ->willReturn(1);

        $this->dbStorageHandler
            ->expects($this->once())
            ->method('getConnection')
            ->willReturn($pdo);

        $pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $queryData = $this->createMock(QueryDataInterface::class);

        $queryData->expects($this->once())
                  ->method('getQuery')
                  ->willReturn($query);

        $queryData->expects($this->never())
                  ->method('getMapClassName');

        $out = $this->database->runQuery($queryData);

        $this->assertEquals(1, $out->getAffectedNumRows());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbStorageHandler = $this->createMock(DbStorageHandler::class);

        $this->database = new Database($this->dbStorageHandler, $this->application->getEventDispatcher());
    }
}
