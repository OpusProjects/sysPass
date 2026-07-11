<?php
declare(strict_types=1);
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Tests\Support;

use PDO;
use RuntimeException;
use SP\Infrastructure\Database\DatabaseException;

use function SP\processException;
use function SP\Tests\getDbHandler;

/**
 *
 */
trait DatabaseTrait
{
    protected static bool $loadFixtures = false;
    private static ?PDO $conn = null;

    protected static function getRowCount(string $table): int
    {
        if (!self::$conn) {
            self::setConnection();
        }

        $sql = sprintf('SELECT count(*) FROM `%s`', $table);

        return (int)self::$conn->query($sql)->fetchColumn();
    }

    protected static function setConnection(): void
    {
        if (!self::$conn) {
            try {
                self::$conn = getDbHandler()->getConnection();
            } catch (DatabaseException $e) {
                processException($e);

                exit(1);
            }
        }
    }

    /**
     * @throws DatabaseException
     */
    protected static function loadFixtures(): void
    {
        // No mysql client binary is available in the test container (and the
        // fixture files live on a vfsStream URL): run them through PDO instead
        $conn = DatabaseUtil::getConnection();

        foreach (FIXTURE_FILES as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Cannot read fixtures from: %s', $file));
            }

            // Iterate over every result set so an error in ANY statement of the
            // multi-statement batch surfaces here, not on the next query
            $statement = $conn->query($sql);

            /** @noinspection PhpStatementHasEmptyBodyInspection */
            while ($statement->nextRowset()) {
                // drain
            }

            $statement->closeCursor();
        }
    }

    protected static function truncateTable(string $table): void
    {
        if (!self::$conn) {
            self::setConnection();
        }

        $sql = sprintf('TRUNCATE TABLE `%s`', $table);

        self::$conn->exec($sql);
    }
}
