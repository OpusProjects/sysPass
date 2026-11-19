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

namespace SP\Domain\Upgrade\Ports;

use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;

/**
 * Reads and replaces the serialized columns sysPass 3.2 wrote as PHP serialization
 */
interface LegacySerializedDataRepository
{
    /**
     * The rows whose column holds a value that is not JSON
     *
     * @return array<int, array{id: int, value: string, type: ?string}>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function findNotJson(string $table, string $column, ?string $typeColumn = null): array;

    /**
     * Replaces one row's value, if it still holds the one that was read
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function replace(string $table, string $column, int $id, string $expected, string $json): bool;
}
