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

namespace SP\Infrastructure\Adapter\Out\User\Repositories;

use Exception;
use SP\Domain\Account\Models\Account as AccountModel;
use SP\Domain\Account\Models\AccountToUserGroup as AccountToUserGroupModel;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\User\Models\UserToUserGroup as UserToUserGroupModel;
use SP\Domain\User\Ports\UserGroupRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;

use function SP\__u;

/**
 * Class UserGroup
 *
 * @template T of UserGroupModel
 * @implements UserGroupRepository<T>
 */
final class UserGroup extends BaseRepository implements UserGroupRepository
{
    /**
     * Deletes an item
     *
     * @param int $id
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $id): QueryResult
    {
        $query = $this->queryFactory
            ->newDelete()
            ->from(UserGroupModel::TABLE)
            ->where('id = :id', ['id' => $id])
            ->limit(1);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while deleting the group'));

        return $this->db->runQuery($queryData);
    }

    /**
     * Returns the items that are using the given group id
     *
     * @param int $userGroupId
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUsage(int $userGroupId): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(UserModel::TABLE)
            ->cols(['userGroupId AS id', '"User" AS ref'])
            ->where('userGroupId = :userGroupId1')
            ->unionAll()
            ->from(UserToUserGroupModel::TABLE)
            ->cols(['userGroupId AS id', '"UserGroup" AS ref'])
            ->where('userGroupId = :userGroupId2')
            ->unionAll()
            ->from(AccountToUserGroupModel::TABLE)
            ->cols(['userGroupId AS id', '"AccountToUserGroup" AS ref'])
            ->where('userGroupId = :userGroupId3')
            ->unionAll()
            ->from(AccountModel::TABLE)
            ->cols(['userGroupId AS id', '"Account" AS ref'])
            ->where('userGroupId = :userGroupId4')
            ->bindValues([
                'userGroupId1' => $userGroupId,
                'userGroupId2' => $userGroupId,
                'userGroupId3' => $userGroupId,
                'userGroupId4' => $userGroupId,
            ]);

        return $this->db->runQuery(QueryData::build($query));
    }

    /**
     * Returns the users that are using the given group id
     *
     * @param int $userGroupId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     * @throws Exception
     */
    public function getUsageByUsers(int $userGroupId): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(
                [
                    sprintf('%s.id AS id', UserModel::TABLE),
                    sprintf('%s.name as name', UserModel::TABLE),
                    sprintf('%s.login as login', UserModel::TABLE),
                    'ref'
                ]
            )
            ->fromSubSelect(
                $this->queryFactory
                    ->newSelect()
                    ->from(UserModel::TABLE)
                    ->cols(['id', '"User" AS ref'])
                    ->where('userGroupId = :userGroupId1')
                    ->unionAll()
                    ->from(UserToUserGroupModel::TABLE)
                    ->cols(['userId AS id', '"UserGroup" AS ref'])
                    ->where('userGroupId = :userGroupId2'),
                'Users'
            )
            ->innerJoin(UserModel::TABLE, sprintf('%s.id = %s.id', UserModel::TABLE, 'Users'))
            ->bindValues([
                'userGroupId1' => $userGroupId,
                'userGroupId2' => $userGroupId,
            ]);

        return $this->db->runQuery(QueryData::build($query));
    }

    /**
     * Returns the item for given id
     *
     * @param int $id
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getById(int $id): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(UserGroupModel::getCols(['users']))
            ->from(UserGroupModel::TABLE)
            ->where('id = :id', ['id' => $id])
            ->limit(1);

        return $this->db->runQuery(QueryData::buildWithMapper($query, UserGroupModel::class));
    }

    /**
     * Returns the item for given name
     *
     * @param string $name
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getByName(string $name): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(UserGroupModel::getCols(['users']))
            ->from(UserGroupModel::TABLE)
            ->where('name = :name', ['name' => $name])
            ->limit(1);

        return $this->db->runQuery(QueryData::buildWithMapper($query, UserGroupModel::class));
    }

    /**
     * Returns all the items
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getAll(): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(UserGroupModel::TABLE)
            ->cols(UserGroupModel::getCols(['users']))
            ->orderBy(['name']);

        return $this->db->runQuery(QueryData::buildWithMapper($query, UserGroupModel::class));
    }

    /**
     * Deletes all the items for given ids
     *
     * @param array<int> $ids
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $ids): QueryResult
    {
        if (empty($ids)) {
            return new QueryResult();
        }

        $query = $this->queryFactory
            ->newDelete()
            ->from(UserGroupModel::TABLE)
            ->where('id IN (:ids)', ['ids' => $ids]);

        return $this->db->runQuery(QueryData::build($query));
    }

    /**
     * Searches for items by a given filter
     *
     * @param ItemSearchDto $itemSearchData
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(UserGroupModel::TABLE)
            ->cols(UserGroupModel::getCols(['users']))
            ->orderBy(['name'])
            ->limit($itemSearchData->getLimitCount())
            ->offset($itemSearchData->getLimitStart());

        if (!empty($itemSearchData->getSearchString())) {
            $query->where('name LIKE :name OR description LIKE :description');

            $search = '%' . $itemSearchData->getSearchString() . '%';

            $query->bindValues(['name' => $search, 'description' => $search]);
        }

        $queryData = QueryData::build($query)->setMapClassName(UserGroupModel::class);

        return $this->db->runQuery($queryData, true);
    }

    /**
     * Creates an item
     *
     * @param UserGroupModel $userGroup
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     */
    public function create(UserGroupModel $userGroup): QueryResult
    {
        if ($this->checkDuplicatedOnAdd($userGroup)) {
            throw DuplicatedItemException::error(__u('Duplicated group name'));
        }

        $query = $this->queryFactory
            ->newInsert()
            ->into(UserGroupModel::TABLE)
            // 'users' is a relation on the model, not a column on the UserGroup table.
            ->cols($userGroup->toArray(null, ['id', 'users']));

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while creating the group'));

        return $this->db->runQuery($queryData);
    }

    /**
     * Checks whether the item is duplicated on adding
     *
     * @param UserGroupModel $userGroup
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    private function checkDuplicatedOnAdd(UserGroupModel $userGroup): bool
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(['id'])
            ->from(UserGroupModel::TABLE)
            ->where('UPPER(:name) = UPPER(name)', ['name' => $userGroup->getName()]);

        return $this->db->runQuery(QueryData::build($query))->getNumRows() > 0;
    }

    /**
     * Updates an item
     *
     * @param UserGroupModel $userGroup
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     */
    public function update(UserGroupModel $userGroup): int
    {
        if ($this->checkDuplicatedOnUpdate($userGroup)) {
            throw DuplicatedItemException::error(__u('Duplicated group name'));
        }

        $query = $this->queryFactory
            ->newUpdate()
            ->table(UserGroupModel::TABLE)
            ->cols($userGroup->toArray(null, ['id', 'users']))
            ->where('id = :id', ['id' => $userGroup->getId()])
            ->limit(1);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while updating the group'));

        return $this->db->runQuery($queryData)->getAffectedNumRows();
    }

    /**
     * Checks whether the item is duplicated on updating
     *
     * @param UserGroupModel $userGroup
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    private function checkDuplicatedOnUpdate(UserGroupModel $userGroup): bool
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(['id'])
            ->from(UserGroupModel::TABLE)
            ->where('id <> :id', ['id' => $userGroup->getId()])
            ->where('UPPER(:name) = UPPER(name)', ['name' => $userGroup->getName()]);

        return $this->db->runQuery(QueryData::build($query))->getNumRows() > 0;
    }
}
