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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `hash` column is how a name is held to be unique, and only the database can hold it.
 *
 * `Category`, `Client` and `Tag` each store a hash of their name and each refuse a duplicate the
 * same way: the repository runs a SELECT, and creates the row if nothing came back. That check is
 * a read followed by a write, so two requests arriving together both find nothing and both insert.
 * What stops them is the unique index underneath — and `Client` had a plain `KEY`, named
 * `uk_Client_01` like its unique siblings but declaring nothing.
 *
 * Inserting two clients with the same hash was accepted by the database, so a second "Acme" was one
 * concurrent request away, with accounts then split across two clients that look identical.
 *
 * The rule is read out of `schemas/dbstructure.sql` rather than from a live database, so it holds
 * for a fresh install, which is the copy the schema file is.
 */
#[Group('unitary')]
class SchemaEnforcesIdentityTest extends TestCase
{
    private const SCHEMA = REAL_APP_ROOT . '/schemas/dbstructure.sql';

    /**
     * Every table whose identity is a hash of its name.
     *
     * @return array<string, array{string}>
     */
    public static function hashedTableProvider(): array
    {
        return [
            'Category' => ['Category'],
            'Client' => ['Client'],
            'ItemPreset' => ['ItemPreset'],
            'PublicLink' => ['PublicLink'],
            'Tag' => ['Tag'],
        ];
    }

    #[Test]
    #[DataProvider('hashedTableProvider')]
    public function aHashColumnIsDeclaredUnique(string $table): void
    {
        $definition = self::tableDefinition($table);

        self::assertMatchesRegularExpression(
            '/UNIQUE KEY\s+`[^`]+`\s*\(`hash`\)/',
            $definition,
            sprintf(
                '%s stores a hash of its name and refuses duplicates with a SELECT before the '
                . 'INSERT, which two concurrent requests both pass. Only a UNIQUE index stops the '
                . 'second row. Naming an index `uk_…` does not declare one.',
                $table
            )
        );
    }

    /**
     * The whole schema, so a table added later with a hash and no unique index is caught even if
     * nobody thinks to list it above.
     */
    #[Test]
    public function noTableHasAHashColumnWithoutAUniqueIndex(): void
    {
        $schema = (string)file_get_contents(self::SCHEMA);

        preg_match_all('/CREATE TABLE `(\w+)`(.*?)\n\)/s', $schema, $tables, PREG_SET_ORDER);

        $missing = [];

        foreach ($tables as [, $name, $definition]) {
            // Only the tables that use a hash as identity: a hash column that is something else —
            // a recovery token, a password — is not a name being held unique.
            if (!isset(self::hashedTableProvider()[$name])) {
                continue;
            }

            if (preg_match('/UNIQUE KEY\s+`[^`]+`\s*\(`hash`\)/', $definition) !== 1) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'Tables with a hash identity and no unique index');
    }

    private static function tableDefinition(string $table): string
    {
        $schema = (string)file_get_contents(self::SCHEMA);

        self::assertSame(
            1,
            preg_match(sprintf('/CREATE TABLE `%s`(.*?)\n\)/s', preg_quote($table, '/')), $schema, $matches),
            sprintf('No CREATE TABLE for %s', $table)
        );

        return $matches[1];
    }
}
