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

namespace SP\Infrastructure\Adapter\Out\Upgrade\Repositories;

use SP\Domain\Common\Models\Simple;
use SP\Domain\Upgrade\Ports\LegacySerializedDataRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;

/**
 * Class LegacySerializedData
 *
 * The table and column names come from LegacySerializedData's own constants, never from a caller.
 */
final class LegacySerializedData extends BaseRepository implements LegacySerializedDataRepository
{
    /**
     * @inheritDoc
     */
    public function findNotJson(string $table, string $column, ?string $typeColumn = null): array
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(array_values(array_filter(['id', $column, $typeColumn])))
            ->from($table)
            ->where(sprintf('%s IS NOT NULL', $column))
            ->orderBy(['id']);

        $rows = $this->db->runQuery(QueryData::build($query)->setMapClassName(Simple::class))->getDataAsArray();
        $found = [];

        foreach ($rows as $row) {
            $value = (string)$row[$column];

            // JSON is what this rewrite writes; anything else is PHP serialization.
            if ($value === '' || str_starts_with($value, '{') || str_starts_with($value, '[')) {
                continue;
            }

            $found[] = [
                'id' => (int)$row['id'],
                'value' => $value,
                'type' => $typeColumn !== null ? (string)$row[$typeColumn] : null,
            ];
        }

        return $found;
    }

    /**
     * @inheritDoc
     */
    public function replace(string $table, string $column, int $id, string $expected, string $json): bool
    {
        // The value read is part of the condition, so a row changed since it was read is left
        // alone rather than overwritten with a conversion of what it used to hold.
        $query = $this->queryFactory
            ->newUpdate()
            ->table($table)
            ->col($column, $json)
            ->where('id = :id')
            ->where(sprintf('%s = :expected', $column))
            ->bindValues(['id' => $id, 'expected' => $expected]);

        return $this->db->runQuery(QueryData::build($query))->getAffectedNumRows() === 1;
    }
}
