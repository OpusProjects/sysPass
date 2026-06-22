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
 * Class AccountToUserRepository
 *
 * @package SP\Infrastructure\Adapter\Out\Account\Repositories
 */
interface AccountToUserRepository extends Repository
{
    /**
     * Delete the association of groups with accounts.
     *
     * @param int $id the account ID
     * @param bool $isEdit
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteTypeByAccountId(int $id, bool $isEdit): void;

    /**
     * Create an association of users with accounts.
     *
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
     * Delete the association of groups with accounts.
     *
     * @param int $id the account ID
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByAccountId(int $id): bool;

    /**
     * Get the list of users of an account.
     *
     * @param int $id the account ID
     *
     * @return QueryResult
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUsersByAccountId(int $id): QueryResult;
}
