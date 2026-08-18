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

namespace SP\Tests\Integration\Domain\Upgrade;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\Database\MysqlFileParser;
use SP\Infrastructure\File\FileHandler;

use function SP\Tests\getDbHandler;

/**
 * A schema migration that stops half way has to be one somebody can run again.
 *
 * `UpgradeDatabase::apply()` runs the statements of a version's file one at a time and writes the
 * new database version only once they have all succeeded — which is right, and which is also why
 * the statements themselves have to be able to fail together. DDL commits as it goes, whatever
 * the application does around it, so two `ALTER`s in a file are two commits: the first stands
 * even when the second is refused.
 *
 * `40024210101.sql` gives `CustomFieldData` the identity it should always have had, dropping the
 * surrogate `id` and making `(moduleId, itemId, definitionId)` the primary key. Written as two
 * statements the drop committed alone, and the key then failed on any installation holding a
 * duplicate of that triple — which the surrogate key had allowed. The column was gone, the
 * version was unchanged, and the retry died on `Can't DROP COLUMN id` before reaching the
 * statement that had actually failed: an upgrade that could neither be finished nor repeated.
 *
 * This runs the real file against a real server, on a table shaped the way an installation's is
 * before the migration, holding the duplicate that provokes the failure.
 */
#[Group('integration')]
final class MigrationIsAtomicTest extends TestCase
{
    private const VERSION_FILE = REAL_APP_ROOT . '/schemas/40024210101.sql';
    private const SCHEMA = 'syspass_migration_probe';

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = getDbHandler()->getConnection();

        // A scratch schema: this rewrites a table, and the fixture database belongs to every
        // other test in the run.
        $this->pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::SCHEMA));
        $this->pdo->exec(sprintf('CREATE DATABASE `%s`', self::SCHEMA));
        $this->pdo->exec(sprintf('USE `%s`', self::SCHEMA));

        // CustomFieldData as it stands before this migration.
        $this->pdo->exec(
            'CREATE TABLE `CustomFieldData` (
                `id`           int(11) NOT NULL AUTO_INCREMENT,
                `moduleId`     int(10) unsigned NOT NULL,
                `itemId`       int(10) unsigned NOT NULL,
                `definitionId` int(10) unsigned NOT NULL,
                `data`         longblob,
                `key`          varbinary(1000),
                PRIMARY KEY (`id`)
            ) ENGINE = InnoDB'
        );
    }

    protected function tearDown(): void
    {
        $this->pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::SCHEMA));

        parent::tearDown();
    }

    /**
     * A duplicate leaves the table exactly as it was, so the upgrade can be run again.
     */
    #[Test]
    public function aRefusedMigrationLeavesTheTableAloneAndCanBeRepeated(): void
    {
        $this->givenARowTwice();

        $before = $this->columns();

        $failed = $this->applyMigration();

        self::assertNotNull($failed, 'a duplicate of the new key must stop this migration');
        self::assertStringContainsString('Duplicate entry', $failed);

        self::assertSame(
            $before,
            $this->columns(),
            'the migration was refused, so the table must be as it was — with `id` still there, or '
            . 'the operator has nothing left to run again'
        );

        // What the operator does next: clear the duplicate, run the upgrade again.
        $this->pdo->exec('DELETE FROM `CustomFieldData` LIMIT 1');

        self::assertNull($this->applyMigration(), 'the same file must succeed once the data allows it');
        self::assertSame(
            ['moduleId', 'itemId', 'definitionId'],
            $this->primaryKeyColumns(),
            'the row is identified by what it describes'
        );
    }

    /**
     * And with nothing in the way it simply applies.
     */
    #[Test]
    public function theMigrationAppliesToATableThatAllowsIt(): void
    {
        $this->pdo->exec('INSERT INTO `CustomFieldData` (`moduleId`, `itemId`, `definitionId`) VALUES (1, 1, 1)');

        self::assertNull($this->applyMigration());
        self::assertSame(['moduleId', 'itemId', 'definitionId'], $this->primaryKeyColumns());
        self::assertNotContains('id', $this->columns(), 'the surrogate key is gone');
    }

    private function givenARowTwice(): void
    {
        $this->pdo->exec(
            'INSERT INTO `CustomFieldData` (`moduleId`, `itemId`, `definitionId`) VALUES (1, 1, 1), (1, 1, 1)'
        );
    }

    /**
     * Runs the version file the way UpgradeDatabase does — the real statements, one at a time,
     * stopping at the first refusal.
     *
     * @return string|null the error, or null when every statement applied
     */
    private function applyMigration(): ?string
    {
        foreach ((new MysqlFileParser(new FileHandler(self::VERSION_FILE)))->parse('$$') as $query) {
            try {
                $this->pdo->exec($query);
            } catch (PDOException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function columns(): array
    {
        $statement = $this->pdo->query('SHOW COLUMNS FROM `CustomFieldData`');

        return array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }

    /**
     * @return string[]
     */
    private function primaryKeyColumns(): array
    {
        $statement = $this->pdo->query("SHOW KEYS FROM `CustomFieldData` WHERE `Key_name` = 'PRIMARY'");

        return array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
    }
}
