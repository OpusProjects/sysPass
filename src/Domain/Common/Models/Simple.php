<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Common\Models;

/**
 * Class Simple
 *
 * This model does not contain any properties.
 *
 * It's intended to be used when returned non-well defined objects from the repository.
 */
final class Simple extends Model
{
    /**
     * Values assigned here (PDO::FETCH_CLASS hydrates rows this way) go into the same outer-property
     * bag the parent exposes, so toArray(includeOuter: true) — and therefore mutate() and
     * Dto::fromModel() — can see them. They used to live in a private array of this class instead,
     * which get_object_vars() in the parent scope cannot reach: every one of those round-trips
     * silently returned an empty model.
     *
     * The parent's __get()/__isset()/offsetGet()/offsetExists() already read that bag, so this class
     * needs no accessor overrides of its own.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setOuterProperty($name, $value);
    }
}
