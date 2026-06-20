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

namespace SP\Infrastructure\Common\Repositories;

/**
 * Trait RepositoryItemTrait
 */
trait RepositoryItemTrait
{
    /**
     * Create a hash from the item's name.
     *
     * This function creates a hash to detect duplicate item names by
     * stripping special characters and normalizing capitalization
     */
    protected function makeItemHash(string $name): string
    {
        $charsSrc = ['.', ' ', '_', ', ', '-', ';', '\'', '"', ':', '(', ')', '|', '/'];

        return sha1(
            strtolower(
                str_replace($charsSrc, '', $name)
            )
        );
    }

    /**
     * Return a string of parameters for a SQL query built from an array
     *
     * @param array $items
     * @param string $placeholder The string to use for the parameters
     *
     * @return string
     */
    protected function buildParamsFromArray(array $items, string $placeholder = '?'): string
    {
        return implode(
            ',',
            array_fill(0, count($items), $placeholder)
        );
    }
}
