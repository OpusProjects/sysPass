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

namespace SP\Infrastructure\Adapter\Out\Account\Repositories;

use SP\Domain\Account\Ports\AccountToUserRepository;
use SP\Domain\Common\Models\Item;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\Query;
use SP\Infrastructure\Adapter\Out\Common\Repositories\RepositoryItemTrait;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;

use function SP\__u;

/**
 * Class AccountToUser
 */
final class AccountToUser extends BaseRepository implements AccountToUserRepository
{
    use RepositoryItemTrait;

    /**
     * Remove the association between groups and accounts.
     *
     * @param int $id the account ID
     * @param bool $isEdit
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteTypeByAccountId(int $id, bool $isEdit): void
    {
        $query = $this->queryFactory
            ->newDelete()
            ->from('AccountToUser')
            ->where('accountId = :accountId')
            ->where('isEdit = :isEdit')
            ->bindValues([
                             'accountId' => $id,
                             'isEdit' => (int)$isEdit,
                         ]);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while deleting the account\'s groups'));

        $this->db->runQuery($queryData);
    }

    /**
     * Create the association between users and accounts.
     *
     * @param int $accountId
     * @param int[] $items
     * @param bool $isEdit
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function addByType(int $accountId, array $items, bool $isEdit = false): void
    {
        $rows = [];
        $bindValues = ['isEdit' => (int)$isEdit];

        foreach (array_values($items) as $i => $item) {
            $rows[] = sprintf('(:accountId_%1$d, :userId_%1$d, :isEdit)', $i);
            $bindValues['accountId_' . $i] = $accountId;
            $bindValues['userId_' . $i] = (int)$item;
        }

        $query = /** @lang SQL */
            'INSERT INTO AccountToUser (accountId, userId, isEdit)
              VALUES ' . implode(',', $rows) . '
              ON DUPLICATE KEY UPDATE isEdit = :isEdit';

        $queryData = QueryData::build(
            Query::buildForMySQL($query, $bindValues)
        )->setOnErrorMessage(__u('Error while updating the account users'));

        $this->db->runQuery($queryData);
    }

    /**
     * Remove the association between groups and accounts.
     *
     * @param int $id the account ID
     *
     * @return void
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByAccountId(int $id): void
    {
        $query = $this->queryFactory
            ->newDelete()
            ->from('AccountToUser')
            ->where('accountId = :accountId')
            ->bindValues([
                             'accountId' => $id,
                         ]);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while deleting the account users'));

        $this->db->runQuery($queryData);
    }

    /**
     * Get the list of users for an account.
     *
     * @param int $id the account ID
     *
     * @return QueryResult<Item>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUsersByAccountId(int $id): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols([
                       'User.id',
                       'User.name',
                       'User.login',
                       'AccountToUser.isEdit',
                   ])
            ->from('AccountToUser')
            ->join('INNER', 'User', 'User.id = AccountToUser.userId')
            ->where('AccountToUser.accountId = :accountId')
            ->bindValues(['accountId' => $id])
            ->orderBy(['User.name ASC']);

        return $this->db->runQuery(QueryData::build($query)->setMapClassName(Item::class));
    }
}
