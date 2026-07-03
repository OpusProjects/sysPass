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

namespace SP\Tests\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\Database\DatabaseUtil;

use function SP\Tests\getDbHandler;

/**
 * Class DatabaseUtilTest
 *
 * escape() quotes values through a real PDO connection, so this is an
 * integration test — a mocked quote() could not prove the round-trip.
 */
#[Group('integration')]
class DatabaseUtilTest extends TestCase
{
    private DatabaseUtil $databaseUtil;
    private PDO          $pdo;

    public static function binaryEdgeProvider(): array
    {
        // Blob bytes that trim() would strip from an edge: space, \t, \n, \r, \0, \x0B
        return [
            'trailing newline' => ["secret\n"],
            'leading null'     => ["\0secret"],
            'both edges tab'   => ["\tsecret\t"],
            'trailing space'   => ['secret '],
            'vertical tab'     => ["secret\x0B"],
            'plain text'       => ['just a value'],
            'text with quote'  => ["O'Brien"],
        ];
    }

    /**
     * escape() must not truncate a value edged by a whitespace/null byte: those
     * are ordinary bytes of the varbinary pass/key blobs the DB backup dumps.
     */
    #[DataProvider('binaryEdgeProvider')]
    public function testEscapePreservesEveryByte(string $value): void
    {
        $quoted = $this->databaseUtil->escape($value);

        // Round-trip the quoted literal back through the server: SELECT <quoted>
        $roundTripped = $this->pdo->query('SELECT ' . $quoted)->fetchColumn();

        $this->assertSame($value, $roundTripped);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $handler = getDbHandler();
        $this->databaseUtil = new DatabaseUtil($handler);
        $this->pdo = $handler->getConnection();
    }
}
