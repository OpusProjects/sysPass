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

namespace SP\Tests\Unit\Infrastructure\Database;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Database\Ports\DatabaseUtilService;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The installer and the upgrade decide whether they have anything to work with from here, and the
 * SQL backup quotes every value it writes through escape().
 *
 * Everything in the class swallows the database's own exception and answers with a value instead,
 * which is the part worth pinning: a connection that cannot be made has to read as "no", not as a
 * fatal on the install page.
 */
#[Group('unitary')]
class DatabaseUtilTest extends UnitaryTestCase
{
    /**
     * The schema is complete only when every table and view is there. Anything less means the
     * install did not finish, and the installer has to be able to tell.
     */
    #[Test]
    public function aCompleteSchemaChecksOut()
    {
        $expected = count(DatabaseUtilService::TABLES) + count(DatabaseUtilService::VIEWS);

        $util = new DatabaseUtil($this->buildStorageReturning((string)$expected));

        self::assertTrue($util->checkDatabaseTables('syspass'));
    }

    /**
     * One table short is not a schema.
     */
    #[Test]
    public function anIncompleteSchemaDoesNot()
    {
        $expected = count(DatabaseUtilService::TABLES) + count(DatabaseUtilService::VIEWS) - 1;

        $util = new DatabaseUtil($this->buildStorageReturning((string)$expected));

        self::assertFalse($util->checkDatabaseTables('syspass'));
    }

    /**
     * The count is asked of the named database only, so another schema on the same server carrying
     * the same table names cannot make an empty one look installed.
     */
    #[Test]
    public function theSchemaIsCountedInTheNamedDatabaseOnly()
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('0');

        $connection = $this->createMock(PDO::class);
        $connection->expects(self::once())
                   ->method('query')
                   ->with(self::stringContains("table_schema = 'other_db'"))
                   ->willReturn($statement);

        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($connection);

        (new DatabaseUtil($storage))->checkDatabaseTables('other_db');
    }

    /**
     * A database that cannot be reached reads as "not installed" rather than throwing: this runs on
     * the install page, before there is anything to connect to.
     */
    #[Test]
    public function aSchemaCheckOnAnUnreachableDatabaseIsFalse()
    {
        $util = new DatabaseUtil($this->buildStorageThatCannotConnect());

        self::assertFalse($util->checkDatabaseTables('syspass'));
    }

    #[Test]
    public function aConnectionThatCanBeMadeChecksOut()
    {
        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($this->createStub(PDO::class));

        self::assertTrue((new DatabaseUtil($storage))->checkDatabaseConnection());
    }

    #[Test]
    public function aConnectionThatCannotBeMadeDoesNot()
    {
        self::assertFalse((new DatabaseUtil($this->buildStorageThatCannotConnect()))->checkDatabaseConnection());
    }

    /**
     * The server details shown on the configuration page come straight off the connection.
     */
    #[Test]
    public function theServerInformationIsReadFromTheConnection()
    {
        $connection = $this->createStub(PDO::class);
        $connection->method('getAttribute')
                   ->willReturnCallback(
                       static fn(int $attribute) => match ($attribute) {
                           PDO::ATTR_SERVER_VERSION => '10.11.6-MariaDB',
                           PDO::ATTR_CLIENT_VERSION => 'mysqlnd 8.5',
                           PDO::ATTR_SERVER_INFO => 'Uptime: 1',
                           PDO::ATTR_CONNECTION_STATUS => 'db via TCP/IP',
                           default => null
                       }
                   );

        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($connection);

        $info = (new DatabaseUtil($storage))->getDBinfo();

        self::assertSame('10.11.6-MariaDB', $info['SERVER_VERSION']);
        self::assertSame('mysqlnd 8.5', $info['CLIENT_VERSION']);
        self::assertSame('Uptime: 1', $info['SERVER_INFO']);
        self::assertSame('db via TCP/IP', $info['CONNECTION_STATUS']);
    }

    /**
     * With no connection there is nothing to report, and the page still renders.
     */
    #[Test]
    public function theServerInformationIsEmptyWithoutAConnection()
    {
        self::assertSame([], (new DatabaseUtil($this->buildStorageThatCannotConnect()))->getDBinfo());
    }

    /**
     * The SQL backup writes every value through here, so the quoting is the driver's own.
     */
    #[Test]
    public function aValueIsQuotedByTheDriver()
    {
        $connection = $this->createStub(PDO::class);
        $connection->method('quote')->willReturnCallback(
            static fn(string $value) => "'" . str_replace("'", "''", $value) . "'"
        );

        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($connection);

        self::assertSame("'O''Brien'", (new DatabaseUtil($storage))->escape("O'Brien"));
    }

    /**
     * A value edged by whitespace keeps it. The backup quotes the varbinary pass and key blobs
     * through here, and their first and last byte are random — trimming would corrupt the dump.
     */
    #[Test]
    public function quotingDoesNotTrimTheValue()
    {
        $connection = $this->createStub(PDO::class);
        $connection->method('quote')->willReturnCallback(static fn(string $value) => "'" . $value . "'");

        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($connection);

        self::assertSame("' padded '", (new DatabaseUtil($storage))->escape(' padded '));
    }

    /**
     * With no connection to quote through, the value is handed back as it came rather than being
     * silently dropped.
     */
    #[Test]
    public function anUnquotableValueIsReturnedAsItCame()
    {
        self::assertSame('raw', (new DatabaseUtil($this->buildStorageThatCannotConnect()))->escape('raw'));
    }

    private function buildStorageReturning(string $count): DbStorageHandler
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn($count);

        $connection = $this->createStub(PDO::class);
        $connection->method('query')->willReturn($statement);

        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willReturn($connection);

        return $storage;
    }

    private function buildStorageThatCannotConnect(): DbStorageHandler
    {
        $storage = $this->createStub(DbStorageHandler::class);
        $storage->method('getConnection')->willThrowException(SPException::error('No connection'));

        return $storage;
    }
}
