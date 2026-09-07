<?php
declare(strict_types=1);
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

namespace SP\Domain\Common\Dtos;

use SP\Domain\Common\Models\Item;

/**
 * Trait ItemDataTrait
 */
trait ItemDataTrait
{
    /**
     * Keep only the `Item`s out of whatever was handed over.
     *
     * The parameter is deliberately `mixed[]` rather than `Item[]`: every caller takes a bare
     * `array` at runtime — `AccountAclDto`'s constructor and `AccountEnrichedDto`'s `with*()`
     * methods all declare `array $x` — and the values arrive from the database and from
     * deserialized DTOs, so this filter is the thing that makes the array an `Item[]`. Annotating
     * the input as `Item[]` claimed the guarantee this method exists to provide, which is why
     * PHPStan 2.2.12 began reporting the `instanceof` as always true.
     *
     * @param mixed[] $items
     *
     * @return Item[]
     */
    private static function buildFromItemData(array $items): array
    {
        return array_filter($items, static fn(mixed $value): bool => $value instanceof Item);
    }
}
