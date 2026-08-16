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

namespace SP\Tests\Unit\Domain\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Database\DatabaseConnectionData;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Which database the application talks to, when the environment has an opinion.
 *
 * `CoreDefinitions` asks `hasEnvironmentConfig()` whether the environment describes a connection,
 * and takes the whole connection from there if it does — otherwise from `config.xml`. It is an
 * all-or-nothing switch, and what flips it therefore decides whether a deployment's environment is
 * honoured or silently ignored.
 *
 * A socket is a complete way to reach the database: `MysqlHandler` writes `unix_socket=…` *instead
 * of* `host=…`, so a socket connection has no host by design. The switch nonetheless asked for
 * `DB_SERVER` alone, so `DB_SOCKET` on its own did nothing — and the only way to make it take
 * effect was to also set a host the DSN would then ignore.
 */
#[Group('unitary')]
class DatabaseConnectionDataTest extends UnitaryTestCase
{
    /** @var array<string, mixed> */
    private array $env;

    protected function setUp(): void
    {
        parent::setUp();

        // getFromEnv() reads $_ENV and $_SERVER, so both are cleared and restored around each test.
        $this->env = ['env' => $_ENV, 'server' => $_SERVER];

        foreach (['DB_SERVER', 'DB_SOCKET', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env['env'];
        $_SERVER = $this->env['server'];

        parent::tearDown();
    }

    #[Test]
    public function aSocketOnItsOwnDescribesAConnection(): void
    {
        $_ENV['DB_SOCKET'] = '/run/mysqld/mysqld.sock';

        self::assertTrue(DatabaseConnectionData::hasEnvironmentConfig());
    }

    #[Test]
    public function aHostOnItsOwnDescribesAConnection(): void
    {
        $_ENV['DB_SERVER'] = 'db.example.com';

        self::assertTrue(DatabaseConnectionData::hasEnvironmentConfig());
    }

    /**
     * Naming a database without saying where it lives is not a connection, and must leave
     * `config.xml` in charge rather than replacing it with a host of null.
     */
    #[Test]
    public function anEnvironmentThatNamesNoServerOrSocketDescribesNothing(): void
    {
        $_ENV['DB_NAME'] = 'syspass';
        $_ENV['DB_USER'] = 'syspass';

        self::assertFalse(DatabaseConnectionData::hasEnvironmentConfig());
    }

    #[Test]
    public function anEmptyEnvironmentDescribesNothing(): void
    {
        self::assertFalse(DatabaseConnectionData::hasEnvironmentConfig());
    }

    #[Test]
    public function theConnectionIsReadFromTheEnvironment(): void
    {
        $_ENV['DB_SERVER'] = 'db.example.com';
        $_ENV['DB_PORT'] = '3307';
        $_ENV['DB_NAME'] = 'syspass';
        $_ENV['DB_USER'] = 'syspass_user';
        $_ENV['DB_PASS'] = 'a_password';
        $_ENV['DB_SOCKET'] = '/run/mysqld/mysqld.sock';

        $connection = DatabaseConnectionData::getFromEnvironment();

        self::assertSame('db.example.com', $connection->getDbHost());
        self::assertSame(3307, $connection->getDbPort());
        self::assertSame('syspass', $connection->getDbName());
        self::assertSame('syspass_user', $connection->getDbUser());
        self::assertSame('a_password', $connection->getDbPass());
        self::assertSame('/run/mysqld/mysqld.sock', $connection->getDbSocket());
    }

    /**
     * An unset port is null rather than zero, because `MysqlHandler` writes `port=…` into the DSN
     * only when there is one, and `port=0` is not a port.
     */
    #[Test]
    public function anAbsentPortIsNotAPort(): void
    {
        $_ENV['DB_SERVER'] = 'db.example.com';

        self::assertNull(DatabaseConnectionData::getFromEnvironment()->getDbPort());
    }
}
