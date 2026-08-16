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

namespace SP\Application\Account\Ports;

use SP\Domain\Account\Dtos\AccountCacheDto;

/**
 * Class AccountCacheService
 */
interface AccountCacheService
{
    /**
     * Return the accesses from the cache
     */
    public function getCacheForAccount(int $accountId, int $dateEdit): AccountCacheDto;

    /**
     * Fill the cache for a page of accounts in one pass.
     *
     * The listing asks for every account it shows, and doing that one at a time is two queries a
     * row. Calling this first makes each of those a cache hit.
     *
     * @param array<int, int> $dateEditByAccountId the accounts to load, and the edit time each
     *                                             cached entry has to be at least as new as
     */
    public function warmUpFor(array $dateEditByAccountId): void;
}
