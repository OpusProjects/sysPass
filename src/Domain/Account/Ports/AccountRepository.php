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

use SP\Domain\Account\Adapters\AccountPassItemWithIdAndName as AccountPassItemWithIdAndNameModel;
use SP\Domain\Account\Dtos\EncryptedPassword;
use SP\Domain\Account\Models\Account;
use SP\Domain\Account\Models\AccountSearchView as AccountSearchViewModel;
use SP\Domain\Account\Models\AccountView as AccountViewModel;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Common\Ports\Repository;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Common\Dtos\QueryResult;

/**
 * Class AccountRepository
 *
 * @package SP\Domain\Account\Ports
 */
interface AccountRepository extends Repository
{
    /**
     * Returns the total number of accounts
     *
     * @return QueryResult<Simple>
     * @throws QueryException
     * @throws ConstraintException
     */
    public function getTotalNumAccounts(): QueryResult;

    /**
     * @param int $accountId
     *
     * @return QueryResult<AccountPassItemWithIdAndNameModel>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getPasswordForId(int $accountId): QueryResult;

    /**
     * @param int $accountId
     *
     * @return QueryResult<AccountPassItemWithIdAndNameModel>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getPasswordHistoryForId(int $accountId): QueryResult;

    /**
     * Increments the password-view counter of an account in the database
     *
     * @param int $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function incrementDecryptCounter(int $accountId): QueryResult;

    /**
     * Updates the password of an account in the database.
     *
     * @param int $accountId
     * @param Account $account
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function editPassword(int $accountId, Account $account): QueryResult;

    /**
     * Updates the password of an account in the database.
     *
     * @param int $accountId
     * @param EncryptedPassword $encryptedPassword
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function updatePassword(int $accountId, EncryptedPassword $encryptedPassword): QueryResult;

    /**
     * Restores an account from the history.
     *
     * @param int $accountId
     * @param Account $account
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function restoreModified(int $accountId, Account $account): QueryResult;

    /**
     * Updates an item for bulk action
     *
     * @param int $accountId
     * @param Account $account
     * @param bool $changeOwner
     * @param bool $changeUserGroup
     *
     * @return QueryResult<Simple>
     * @throws SPException
     */
    public function updateBulk(int $accountId, Account $account, bool $changeOwner, bool $changeUserGroup): QueryResult;

    /**
     * Increments the visit counter of an account in the database
     *
     * @param int $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function incrementViewCounter(int $accountId): QueryResult;

    /**
     * Retrieves the data of an account.
     *
     * @param int $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getDataForLink(int $accountId): QueryResult;

    /**
     * @param int|null $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getForUser(?int $accountId = null): QueryResult;

    /**
     * @param int $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getLinked(int $accountId): QueryResult;

    /**
     * Retrieves the password-related data of all accounts.
     *
     * @return QueryResult<Account>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getAccountsPassData(): QueryResult;

    /**
     * Creates a new account in the database
     *
     * @param Account $account
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function create(Account $account): QueryResult;

    /**
     * Deletes the data of an account in the database.
     *
     * @param int $accountId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $accountId): QueryResult;

    /**
     * Updates an item
     *
     * @param int $accountId
     * @param Account $account
     * @param bool $changeOwner
     * @param bool $changeUserGroup
     *
     * @return QueryResult<Simple>
     * @throws SPException
     */
    public function update(int $accountId, Account $account, bool $changeOwner, bool $changeUserGroup): QueryResult;

    /**
     * Returns the item for given id with referential data
     *
     * @param int $accountId
     *
     * @return QueryResult<AccountViewModel>
     */
    public function getByIdEnriched(int $accountId): QueryResult;

    /**
     * Returns the item for given id
     *
     * @param int $accountId
     *
     * @return QueryResult<Account>
     */
    public function getById(int $accountId): QueryResult;

    /**
     * Returns all the items
     *
     * @return QueryResult<Account>
     */
    public function getAll(): QueryResult;

    /**
     * Deletes all the items for given ids
     *
     * @param int[] $accountsId
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $accountsId): QueryResult;

    /**
     * Searches for items by a given filter
     *
     * @param ItemSearchDto $itemSearchData
     *
     * @return QueryResult<AccountSearchViewModel>
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult;

    /**
     * Create an account from deleted
     *
     * @param Account $account
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function createRemoved(Account $account): QueryResult;
}
