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

namespace SP\Application\Account\Services;

use SP\Core\Application;
use SP\Domain\Account\Dtos\AccountHistoryCreateDto;
use SP\Domain\Account\Dtos\AccountHistoryDto;
use SP\Domain\Account\Dtos\EncryptedPassword;
use SP\Domain\Account\Models\AccountHistory as AccountHistoryModel;
use SP\Domain\Account\Ports\AccountHistoryRepository;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Infrastructure\Database\QueryResult;

use function SP\__u;

/**
 * Class AccountHistory
 */
final class AccountHistory extends Service implements AccountHistoryService
{
    public function __construct(
        Application                               $application,
        private readonly AccountHistoryRepository $accountHistoryRepository
    ) {
        parent::__construct($application);
    }

    /**
     * Returns the item for given id
     *
     * @param int $id
     * @return AccountHistoryDto
     * @throws NoSuchItemException
     * @throws SPException
     */
    public function getById(int $id): AccountHistoryDto
    {
        $results = $this->accountHistoryRepository->getById($id);

        if ($results->getNumRows() === 0) {
            throw new NoSuchItemException(__u('Error while retrieving account\'s data'));
        }

        return AccountHistoryDto::fromResult($results, AccountHistoryModel::class);
    }

    /**
     * Returns the history list for an account.
     *
     * @param int $id
     *
     * @return Simple[] With the records keyed by id and date - user as the value
     */
    public function getHistoryForAccount(int $id): array
    {
        return $this->accountHistoryRepository->getHistoryForAccount($id)->getDataAsArray();
    }

    /**
     * @param ItemSearchDto $itemSearchData
     *
     * @return QueryResult<Simple>
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult
    {
        return $this->accountHistoryRepository->search($itemSearchData);
    }

    /**
     * Creates a new account in the database
     *
     * @param AccountHistoryCreateDto $dto
     *
     * @return int
     */
    public function create(AccountHistoryCreateDto $dto): int
    {
        return $this->accountHistoryRepository->create($dto);
    }

    /**
     * @return AccountHistoryModel[]
     * @throws SPException
     */
    public function getAccountsPassData(): array
    {
        return $this->accountHistoryRepository->getAccountsPassData()->getDataAsArray(AccountHistoryModel::class);
    }

    /**
     * Deletes an account's data from the database.
     *
     * @param int $id
     *
     * @throws ServiceException
     */
    public function delete(int $id): void
    {
        if (!$this->accountHistoryRepository->delete($id)) {
            throw new ServiceException(__u('Error while deleting the account'));
        }
    }

    /**
     * Deletes all the items for given ids
     *
     * @param int[] $ids
     *
     * @return int
     * @throws ServiceException
     */
    public function deleteByIdBatch(array $ids): int
    {
        return $this->accountHistoryRepository->transactionAware(function () use ($ids) {
            return $this->accountHistoryRepository->deleteByIdBatch($ids);
        }, $this);
    }

    /**
     * Deletes all the items for given accounts id
     *
     * @param int[] $ids
     *
     * @return int
     */
    public function deleteByAccountIdBatch(array $ids): int
    {
        return $this->accountHistoryRepository->deleteByAccountIdBatch($ids);
    }

    /**
     * @param int $accountId
     * @param EncryptedPassword $encryptedPassword
     *
     * @throws ServiceException
     */
    public function updatePasswordMasterPass(int $accountId, EncryptedPassword $encryptedPassword): void
    {
        if (!$this->accountHistoryRepository->updatePassword($accountId, $encryptedPassword)) {
            throw new ServiceException(__u('Error while updating the password'));
        }
    }
}
