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

namespace SP\Domain\Account\Ports;

use SP\Domain\Common\Ports\Repository;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Database\QueryResult;

/**
 * Class AccountFavoriteRepository
 *
 * @package SP\Infrastructure\Adapter\Out\Account\Repositories
 */
interface AccountToFavoriteRepository extends Repository
{
    /**
     * Get an array with the Ids of favorite accounts
     *
     * @param $id int The user Id
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getForUserId(int $id): QueryResult;

    /**
     * Add an account to the favorites list
     *
     * @param $accountId int The account Id
     * @param $userId    int The user Id
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function add(int $accountId, int $userId): int;

    /**
     * Remove an account from the favorites list
     *
     * @param $accountId int The account Id
     * @param $userId    int The user Id
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $accountId, int $userId): bool;
}
