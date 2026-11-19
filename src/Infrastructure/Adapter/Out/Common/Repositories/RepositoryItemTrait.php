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

namespace SP\Infrastructure\Adapter\Out\Common\Repositories;

/**
 * Trait RepositoryItemTrait
 */
trait RepositoryItemTrait
{
    /**
     * Create a hash from the item's name.
     *
     * This function creates a hash to detect duplicate item names by
     * stripping special characters and normalizing capitalization.
     *
     * MD5, as sysPass 3.2 computed it: every installation upgrading from 3.2 holds these hashes in
     * the unique `hash` column of Category, Client and Tag. This rewrite had switched to SHA-1
     * without converting them, so a pre-existing item's hash matched nothing a new name produced —
     * "AcmeCorp" was created beside an existing "Acme Corp", past both the check and the unique
     * key. It is a de-duplication key, not a security hash.
     */
    protected function makeItemHash(string $name): string
    {
        $charsSrc = ['.', ' ', '_', ', ', '-', ';', '\'', '"', ':', '(', ')', '|', '/'];

        return md5(
            strtolower(
                str_replace($charsSrc, '', trim($name))
            )
        );
    }
}
