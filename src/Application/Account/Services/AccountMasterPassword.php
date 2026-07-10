<?php
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

declare(strict_types=1);

namespace SP\Application\Account\Services;

use Exception;
use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Account\Dtos\EncryptedPassword;
use SP\Domain\Account\Models\Account;
use SP\Domain\Account\Models\AccountHistory as AccountHistoryModel;
use SP\Application\Account\Ports\AccountCryptService;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountMasterPasswordService;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Crypt\Dtos\UpdateMasterPassRequest;

use function SP\__;
use function SP\__u;
use function SP\logger;

/**
 * Class AccountMasterPassword
 */
final class AccountMasterPassword extends Service implements AccountMasterPasswordService
{
    public function __construct(
        Application                            $application,
        private readonly AccountService        $accountService,
        private readonly AccountHistoryService $accountHistoryService,
        private readonly CryptInterface        $crypt,
        private readonly AccountCryptService   $accountCryptService
    ) {
        parent::__construct($application);
    }

    /**
     * Updates the passwords of all accounts with the new master password.
     *
     * @param UpdateMasterPassRequest $updateMasterPassRequest
     * @throws ServiceException
     */
    public function updateMasterPassword(UpdateMasterPassRequest $updateMasterPassRequest): void
    {
        $errorCount = 0;

        try {
            $this->eventDispatcher->notify(new Event(
                'update.masterPassword.accounts.start',
                $this,
                EventMessage::build()
                                ->addDescription(__u('Update Master Password'))
            ));

            [$eventMessage, $errorCount] = $this->processAccounts(
                $this->accountService->getAccountsPassData(),
                function (int $accountId, EncryptedPassword $encryptedPassword) {
                    $this->accountService->updatePasswordMasterPass($accountId, $encryptedPassword);
                },
                $updateMasterPassRequest
            );

            $this->eventDispatcher->notify(new Event('update.masterPassword.accounts.end', $this, $eventMessage));
        } catch (Exception $e) {
            $this->eventDispatcher->notify(new Event('exception', $e));

            throw ServiceException::error(
                __u('Error while updating the accounts\' passwords'),
                null,
                $e->getCode(),
                $e
            );
        }

        if ($errorCount > 0) {
            throw ServiceException::error(
                sprintf(
                    __u('%d account(s) could not be re-encrypted; rotation aborted, master password unchanged'),
                    $errorCount
                )
            );
        }
    }

    /**
     * Shared by both the live-account and account-history rotation paths (see the two
     * call sites below), so $accounts holds either model depending on the caller.
     *
     * @param array<Account|AccountHistoryModel> $accounts
     * @param callable $passUpdater
     * @param UpdateMasterPassRequest $updateMasterPassRequest
     *
     * @return array{0: EventMessage<mixed>, 1: int} Tuple of [eventMessage, errorCount]
     */
    private function processAccounts(
        array                   $accounts,
        callable                $passUpdater,
        UpdateMasterPassRequest $updateMasterPassRequest
    ): array {
        set_time_limit(0);

        $accountsOk = [];
        $errorCount = 0;
        $counter = 0;
        $startTime = time();
        $numAccounts = count($accounts);

        $eventMessage = EventMessage::build();

        if ($numAccounts === 0) {
            $eventMessage->addDescription(__u('There are no accounts for processing'));
            $eventMessage->addDetail(__u('Accounts updated'), __u('N/A'));
            $eventMessage->addDetail(__u('Errors'), 0);

            return [$eventMessage, 0];
        }

        $configData = $this->config->getConfigData();
        $currentMasterPassHash = $updateMasterPassRequest->getCurrentHash();

        foreach ($accounts as $account) {
            // Do not make changes if running in demo mode
            if ($configData->isDemoEnabled()) {
                $accountsOk[] = $account->getId();
                continue;
            }

            if ($counter % 100 === 0) {
                $eta = self::getETA($startTime, $counter, $numAccounts);

                logger(
                    sprintf(
                        __('Updated accounts: %d / %d - %d%% - ETA: %ds (%.2f/s)'),
                        $counter,
                        $numAccounts,
                        round(($counter * 100) / $numAccounts, 2),
                        $eta[0],
                        $eta[1]
                    )
                );
            }

            if (isset($account->mPassHash) && $account->mPassHash !== $currentMasterPassHash) {
                $eventMessage->addDescription(__u('The record\'s master password does not match'));
                $eventMessage->addDetail($account->getName(), $account->getId());
                continue;
            }

            try {
                $encryptedPassword = $this->accountCryptService
                    ->getPasswordEncrypted(
                        $this->crypt->decrypt(
                            $account->getPass() ?? '',
                            $account->getKey() ?? '',
                            $updateMasterPassRequest->getCurrentMasterPass()
                        ),
                        $updateMasterPassRequest->getNewMasterPass()
                    );

                // Call the specific updater
                $passUpdater($account->getId() ?? 0, $encryptedPassword);

                $accountsOk[] = $account->getId();
                $counter++;
            } catch (SPException $e) {
                $this->eventDispatcher->notify(new Event('exception', $e));

                $errorCount++;

                $eventMessage->addDescription(__u('Error while updating the account\'s password'));
                $eventMessage->addDetail($account->getName(), $account->getId());
            }
        }

        $eventMessage->addDetail(__u('Accounts updated'), implode(',', $accountsOk));
        $eventMessage->addDetail(__u('Errors'), $errorCount);

        return [$eventMessage, $errorCount];
    }

    /**
     * Returns the approximate time in seconds for an operation
     *
     * @return array{0: int, 1: int|float} With the estimated time and the number of items per second
     */
    public static function getETA(int $startTime, int $numItems, int $totalItems): array
    {
        if ($numItems > 0 && $totalItems > 0) {
            $runtime = max(1, time() - $startTime);
            $eta = (int)((($totalItems * $runtime) / $numItems) - $runtime);

            return [$eta, $numItems / $runtime];
        }

        return [0, 0];
    }

    /**
     * Updates the passwords of all accounts with the new master password.
     *
     * @throws ServiceException
     */
    public function updateHistoryMasterPassword(UpdateMasterPassRequest $updateMasterPassRequest): void
    {
        $errorCount = 0;

        try {
            $this->eventDispatcher->notify(new Event(
                'update.masterPassword.accountsHistory.start',
                $this,
                EventMessage::build()
                                ->addDescription(__u('Update Master Password (H)'))
            ));

            [$eventMessage, $errorCount] = $this->processAccounts(
                $this->accountHistoryService->getAccountsPassData(),
                function (int $accountId, EncryptedPassword $encryptedPassword) {
                    $this->accountHistoryService->updatePasswordMasterPass($accountId, $encryptedPassword);
                },
                $updateMasterPassRequest
            );

            $this->eventDispatcher->notify(new Event('update.masterPassword.accountsHistory.end', $this, $eventMessage));
        } catch (Exception $e) {
            $this->eventDispatcher->notify(new Event('exception', $e));

            throw ServiceException::error(
                __u('Error while updating the accounts\' passwords in history'),
                null,
                $e->getCode(),
                $e
            );
        }

        if ($errorCount > 0) {
            throw ServiceException::error(
                sprintf(
                    __u('%d account(s) could not be re-encrypted; rotation aborted, master password unchanged'),
                    $errorCount
                )
            );
        }
    }
}
