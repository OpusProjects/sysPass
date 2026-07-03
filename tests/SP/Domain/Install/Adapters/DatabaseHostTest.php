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

namespace SP\Tests\Domain\Install\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Install\Adapters\DatabaseHost;

/**
 * Class DatabaseHostTest
 */
#[Group('unitary')]
class DatabaseHostTest extends TestCase
{
    public static function hostProvider(): array
    {
        return [
            'plain host'          => ['db.example.com', 'db.example.com', null, null],
            'host and port'       => ['db.example.com:3307', 'db.example.com', 3307, null],
            'ipv4'                => ['192.168.0.10', '192.168.0.10', null, null],
            'ipv4 and port'       => ['192.168.0.10:3307', '192.168.0.10', 3307, null],
            'bare ipv6'           => ['2001:db8::1', '2001:db8::1', null, null],
            'ipv6 loopback'       => ['::1', '::1', null, null],
            'bracketed ipv6'      => ['[2001:db8::1]', '2001:db8::1', null, null],
            'bracketed ipv6+port' => ['[2001:db8::1]:3307', '2001:db8::1', 3307, null],
            'unix socket'         => ['unix:/var/run/mysqld.sock', null, null, '/var/run/mysqld.sock'],
            'padded host'         => [' localhost ', 'localhost', null, null],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    #[DataProvider('hostProvider')]
    public function testParse(string $spec, ?string $host, ?int $port, ?string $socket): void
    {
        $target = DatabaseHost::parse($spec);

        $this->assertSame($host, $target->host);
        $this->assertSame($port, $target->port);
        $this->assertSame($socket, $target->socket);
    }

    public static function localHostProvider(): array
    {
        return [
            'localhost'            => ['localhost', true],
            'ipv4 loopback'        => ['127.0.0.1', true],
            'ipv6 loopback'        => ['::1', true],
            'unix socket'          => ['unix:/var/run/mysqld.sock', true],
            'remote host'          => ['db.example.com', false],
            'localhost substring'  => ['mylocalhost.example.com', false],
            'loopback substring'   => ['127.0.0.100', false],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    #[DataProvider('localHostProvider')]
    public function testIsLocal(string $spec, bool $expected): void
    {
        $this->assertSame($expected, DatabaseHost::parse($spec)->isLocal());
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testPortOutOfRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database port');

        DatabaseHost::parse('host:99999');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testPortZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid database port');

        DatabaseHost::parse('host:0');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testEmptySocketPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Please, enter the database server');

        DatabaseHost::parse('unix:');
    }
}
