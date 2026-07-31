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

namespace SP\Tests\Unit\Domain\CustomField\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CustomFieldType holds reference data, not user data: CustomFieldDefinition.typeId is a foreign
 * key onto it, so an empty table means no custom field can be defined at all. The seed was dropped
 * once already, and nothing else in the suite would notice — the integration harness stubs the
 * database, so it never reads this table.
 */
#[Group('unitary')]
class CustomFieldTypeSeedTest extends TestCase
{
    /**
     * Ids are part of the contract: existing installs and XML exports refer to them, so they are
     * asserted rather than just the row count.
     */
    private const EXPECTED = [
        1 => 'text',
        2 => 'password',
        3 => 'date',
        4 => 'number',
        5 => 'email',
        6 => 'telephone',
        7 => 'url',
        8 => 'color',
        9 => 'wiki',
        10 => 'textarea',
    ];

    public function testSchemaSeedsEveryCustomFieldType(): void
    {
        $schema = file_get_contents(REAL_APP_ROOT . '/schemas/dbstructure.sql');

        self::assertIsString($schema);

        foreach (self::EXPECTED as $id => $name) {
            self::assertMatchesRegularExpression(
                sprintf('/\(\s*%d\s*,\s*\'%s\'\s*,/', $id, preg_quote($name, '/')),
                $schema,
                sprintf('CustomFieldType id %d (%s) is not seeded by schemas/dbstructure.sql', $id, $name)
            );
        }
    }

    /**
     * The table declares AUTO_INCREMENT = 11, so the seed has to stop at 10 for the next inserted
     * type to get a free id.
     */
    public function testSeedMatchesTheTableAutoIncrement(): void
    {
        $schema = file_get_contents(REAL_APP_ROOT . '/schemas/dbstructure.sql');

        self::assertIsString($schema);
        self::assertCount(10, self::EXPECTED);
        self::assertStringContainsString('AUTO_INCREMENT = 11', $schema);
    }
}
