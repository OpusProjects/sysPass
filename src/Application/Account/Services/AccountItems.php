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

use SP\Application\Application;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Account\Dtos\AccountUpdateDto;
use SP\Application\Account\Ports\AccountItemsService;
use SP\Domain\Account\Ports\AccountToTagRepository;
use SP\Domain\Account\Ports\AccountToUserGroupRepository;
use SP\Domain\Account\Ports\AccountToUserRepository;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;

use function SP\processException;

/**
 * Class AccountItems
 */
final class AccountItems extends Service implements AccountItemsService
{
    public function __construct(
        Application                                   $application,
        private readonly AccountToUserGroupRepository $accountToUserGroupRepository,
        private readonly AccountToUserRepository      $accountToUserRepository,
        private readonly AccountToTagRepository       $accountToTagRepository,
    ) {
        parent::__construct($application);
    }

    /**
     * Updates external items for the account
     *
     * @throws QueryException
     * @throws ConstraintException
     * @throws ServiceException
     */
    public function updateItems(
        bool $userCanChangePermissions,
        int  $accountId,
        AccountUpdateDto $accountUpdateDto
    ): void {
        if ($userCanChangePermissions) {
            // null means the caller said nothing about this list, and an empty array means they
            // said to empty it. It used to be the other way round: null deleted every row of that
            // type and an empty array matched neither branch and did nothing at all.
            //
            // Nothing supplies these on the API — there is no parameter for them — so every REST
            // account edit silently removed all four kinds of sharing from the account it was
            // editing. On the web the form only sets a list when the corresponding `_update` flag
            // is posted, which the theme sends only for a select the user actually changed, so an
            // edit that touched the name or the URL did the same thing. And the bulk edit's
            // "Delete" checkboxes, which post exactly the empty array, were the case that did
            // nothing — so the one way to deliberately clear sharing was the one way that never
            // worked.
            $this->replaceUserGroups($accountId, $accountUpdateDto->userGroupsView, false);
            $this->replaceUserGroups($accountId, $accountUpdateDto->userGroupsEdit, true);
            $this->replaceUsers($accountId, $accountUpdateDto->usersView, false);
            $this->replaceUsers($accountId, $accountUpdateDto->usersEdit, true);
        }

        // Same rule for the tags, which had the same two branches.
        if ($accountUpdateDto->tags !== null) {
            $this->accountToTagRepository->transactionAware(
                function () use ($accountUpdateDto, $accountId) {
                    $this->accountToTagRepository->deleteByAccountId($accountId);

                    if (!empty($accountUpdateDto->tags)) {
                        $this->accountToTagRepository->add($accountId, $accountUpdateDto->tags);
                    }
                },
                $this
            );
        }
    }

    /**
     * Adds external items to the account
     */
    /**
     * Replace an account's shared groups of one kind, or leave them alone when none were supplied.
     *
     * The delete and the add are one transaction, so a failure adding leaves the existing rows
     * rather than an account shared with nobody.
     *
     * @param int[]|null $userGroupIds
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    private function replaceUserGroups(int $accountId, ?array $userGroupIds, bool $isEdit): void
    {
        if ($userGroupIds === null) {
            return;
        }

        $this->accountToUserGroupRepository->transactionAware(
            function () use ($accountId, $userGroupIds, $isEdit) {
                $this->accountToUserGroupRepository->deleteTypeByAccountId($accountId, $isEdit);

                if (!empty($userGroupIds)) {
                    $this->accountToUserGroupRepository->addByType($accountId, $userGroupIds, $isEdit);
                }
            },
            $this
        );
    }

    /**
     * The same for an account's shared users.
     *
     * @param int[]|null $userIds
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    private function replaceUsers(int $accountId, ?array $userIds, bool $isEdit): void
    {
        if ($userIds === null) {
            return;
        }

        $this->accountToUserRepository->transactionAware(
            function () use ($accountId, $userIds, $isEdit) {
                $this->accountToUserRepository->deleteTypeByAccountId($accountId, $isEdit);

                if (!empty($userIds)) {
                    $this->accountToUserRepository->addByType($accountId, $userIds, $isEdit);
                }
            },
            $this
        );
    }

    public function addItems(bool $userCanChangePermissions, int $accountId, AccountCreateDto $accountCreateDto): void
    {
        try {
            if ($userCanChangePermissions) {
                if (null !== $accountCreateDto->userGroupsView
                    && !empty($accountCreateDto->userGroupsView)
                ) {
                    $this->accountToUserGroupRepository->addByType(
                        $accountId,
                        $accountCreateDto->userGroupsView
                    );
                }

                if (null !== $accountCreateDto->userGroupsEdit
                    && !empty($accountCreateDto->userGroupsEdit)
                ) {
                    $this->accountToUserGroupRepository->addByType(
                        $accountId,
                        $accountCreateDto->userGroupsEdit,
                        true
                    );
                }

                if (null !== $accountCreateDto->usersView && !empty($accountCreateDto->usersView)) {
                    $this->accountToUserRepository->addByType($accountId, $accountCreateDto->usersView);
                }

                if (null !== $accountCreateDto->usersEdit && !empty($accountCreateDto->usersEdit)) {
                    $this->accountToUserRepository->addByType($accountId, $accountCreateDto->usersEdit, true);
                }
            }

            if (null !== $accountCreateDto->tags && !empty($accountCreateDto->tags)) {
                $this->accountToTagRepository->add($accountId, $accountCreateDto->tags);
            }
        } catch (SPException $e) {
            processException($e);
        }
    }
}
