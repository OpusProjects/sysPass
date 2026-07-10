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
use SP\Infrastructure\Database\QueryResult;

/**
 * Class AccountToUserGroupRepository
 *
 * @package SP\Infrastructure\Adapter\Out\Account\Repositories
 */
interface AccountToUserGroupRepository extends Repository
{
    /**
     * Get the list with the names of the groups of an account.
     *
     * @param int $id the account ID
     *
     * @return QueryResult
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUserGroupsByAccountId(int $id): QueryResult;

    /**
     * Get the list with the names of the groups of an account.
     *
     * @param int $id
     *
     * @return QueryResult
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUserGroupsByUserGroupId(int $id): QueryResult;

    /**
     * @param $id int
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByUserGroupId(int $id): void;

    /**
     * @param int $id
     * @param bool $isEdit
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteTypeByAccountId(int $id, bool $isEdit): void;

    /**
     * @param int $accountId
     * @param array $items
     * @param bool $isEdit
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function addByType(int $accountId, array $items, bool $isEdit = false): void;

    /**
     * @param $id int
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByAccountId(int $id): void;
}
