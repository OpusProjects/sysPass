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

namespace SP\Domain\Auth\Ports;

use Exception;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Common\Ports\Repository;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Domain\Auth\Models\AuthTokenList as AuthTokenListModel;
use SP\Domain\Common\Models\Simple;
use SP\Infrastructure\Database\QueryResult;

/**
 * Class AuthTokenRepository
 *
 * @template T of AuthTokenModel
 */
interface AuthTokenRepository extends Repository
{
    /**
     * @param int $id
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $id): QueryResult;

    /**
     * Returns the item for given id
     *
     * @param int $authTokenId
     *
     * @return QueryResult<T>
     */
    public function getById(int $authTokenId): QueryResult;

    /**
     * Returns all the items
     *
     * @return QueryResult<T>
     */
    public function getAll(): QueryResult;

    /**
     * Deletes all the items for given ids
     *
     * @param int[] $authTokensId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $authTokensId): QueryResult;

    /**
     * Searches for items by a given filter
     *
     * @param ItemSearchDto $itemSearchData
     *
     * @return QueryResult<AuthTokenListModel>
     * @throws ConstraintException
     * @throws QueryException
     * @throws Exception
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult;

    /**
     * Creates an item
     *
     * @param AuthTokenModel $authToken
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws DuplicatedItemException
     * @throws QueryException
     */
    public function create(AuthTokenModel $authToken): QueryResult;

    /**
     * Get a user's API token
     *
     * @param int $userId
     * @return QueryResult<T>
     */
    public function getTokenByUserId(int $userId): QueryResult;

    /**
     * Updates an item
     *
     * @param AuthTokenModel $authToken
     * @return bool
     * @throws ConstraintException
     * @throws DuplicatedItemException
     * @throws QueryException
     */
    public function update(AuthTokenModel $authToken): bool;

    /**
     * Regenerate the hash of a user's tokens
     *
     * @param int $userId
     * @param string $token
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function refreshTokenByUserId(int $userId, string $token): int;

    /**
     * Regenerate the hash of a user's tokens
     *
     * @param int $userId
     * @param string $vault
     * @param string $hash
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function refreshVaultByUserId(int $userId, string $vault, string $hash): int;

    /**
     * Return a token's data
     *
     * @param $actionId int The action id
     * @param $token    string The security token
     *
     * @return QueryResult<T>
     */
    public function getTokenByToken(int $actionId, string $token): QueryResult;
}
