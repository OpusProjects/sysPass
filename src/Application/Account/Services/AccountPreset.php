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
use SP\Domain\Account\Dtos\AccountDto;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Domain\Account\Ports\AccountToUserGroupRepository;
use SP\Domain\Account\Ports\AccountToUserRepository;
use SP\Domain\Common\Services\Service;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Ports\UserRepository;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Ports\UserGroupRepository;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\ItemPreset\Models\AccountPermission;
use SP\Domain\ItemPreset\Models\ItemPreset as ItemPresetModel;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Domain\Common\Validators\PasswordValidator;

/**
 * Class AccountPreset
 */
final class AccountPreset extends Service implements AccountPresetService
{
    /**
     * @param ItemPresetService<ItemPresetModel> $itemPresetService
     * @param UserRepository<UserModel> $userRepository
     * @param UserGroupRepository<UserGroupModel> $userGroupRepository
     */
    public function __construct(
        Application                                   $application,
        private readonly ItemPresetService $itemPresetService,
        private readonly AccountToUserGroupRepository $accountToUserGroupRepository,
        private readonly AccountToUserRepository      $accountToUserRepository,
        private readonly ConfigDataInterface          $configData,
        private readonly PasswordValidator $passwordValidator,
        private readonly UserRepository               $userRepository,
        private readonly UserGroupRepository          $userGroupRepository
    ) {
        parent::__construct($application);
    }

    /**
     * @param AccountDto $accountDto
     * @return AccountDto
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function checkPasswordPreset(AccountDto $accountDto): AccountDto
    {
        $itemPreset = $this->itemPresetService->getForCurrentUser(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD);

        if ($itemPreset !== null && $itemPreset->getFixed() === 1) {
            $passwordPreset = $itemPreset->hydrate(Password::class);

            if ($passwordPreset !== null) {
                $this->passwordValidator->validate($passwordPreset, $accountDto->pass);

                return $this->clampToPolicyLifetime($accountDto, $passwordPreset);
            }
        }

        return $accountDto;
    }

    /**
     * Holds an account to the policy's password lifetime, without asking about the password.
     *
     * The lifetime a fixed preset sets is a maximum, and it used to be applied only where a
     * password was being set — creating an account, copying one, changing its password. Editing
     * the account writes `passDateChange` just the same, from a field the form offers, and none of
     * those paths clamped it: an account created under a ninety-day policy could be edited a
     * moment later to expire in a decade. Bulk edit could do it to a selection at once.
     *
     * Separate from `checkPasswordPreset()` because that also validates the password against the
     * preset, and an edit legitimately carries none — `PasswordValidator::validate()` measures
     * `mb_strlen('')` against the required length and throws, so calling the whole check here
     * would refuse every edit while a fixed preset existed.
     *
     * @template T of AccountDto
     * @param T $accountDto
     * @return T
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function checkPasswordExpiry(AccountDto $accountDto): AccountDto
    {
        $itemPreset = $this->itemPresetService->getForCurrentUser(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD);

        if ($itemPreset === null || $itemPreset->getFixed() !== 1) {
            return $accountDto;
        }

        $passwordPreset = $itemPreset->hydrate(Password::class);

        return $passwordPreset === null
            ? $accountDto
            : $this->clampToPolicyLifetime($accountDto, $passwordPreset);
    }

    /**
     * @template T of AccountDto
     * @param T $accountDto
     * @return T
     */
    private function clampToPolicyLifetime(AccountDto $accountDto, Password $passwordPreset): AccountDto
    {
        if (!$this->configData->isAccountExpireEnabled()) {
            return $accountDto;
        }

        $expireTimePreset = $passwordPreset->getExpireTime();

        if ($expireTimePreset <= 0) {
            return $accountDto;
        }

        $maxPassDateChange = time() + $expireTimePreset;

        if (empty($accountDto->passDateChange) || $accountDto->passDateChange > $maxPassDateChange) {
            return $accountDto->withPassDateChange($maxPassDateChange);
        }

        return $accountDto;
    }

    /**
     * @param int $accountId
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function addPresetPermissions(int $accountId): void
    {
        $itemPresetData =
            $this->itemPresetService->getForCurrentUser(ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION);

        if ($itemPresetData?->getFixed()) {
            $accountPermission = $itemPresetData->hydrate(AccountPermission::class);

            if ($accountPermission !== null) {
                $userData = $this->context->getUserData();

                // Only ids that still exist. The preset carries them inside a serialized blob, and
                // no foreign key reaches in there — the one on ItemPreset covers the preset's own
                // scope columns, not its contents. So a user or group named in a fixed preset can
                // be deleted with nothing to stop it, and the next account anybody in that
                // preset's scope saved failed on the foreign key these ids do have, inside the
                // transaction, rolling the whole save back. Every account create and edit for
                // those people, until an administrator worked out which preset to edit.
                $usersView = $this->existingUsers(array_diff($accountPermission->getUsersView(), [$userData->id]));
                $usersEdit = $this->existingUsers(array_diff($accountPermission->getUsersEdit(), [$userData->id]));
                $userGroupsView = $this->existingUserGroups(
                    array_diff($accountPermission->getUserGroupsView(), [$userData->userGroupId])
                );
                $userGroupsEdit = $this->existingUserGroups(
                    array_diff($accountPermission->getUserGroupsEdit(), [$userData->userGroupId])
                );

                if (!empty($usersView)) {
                    $this->accountToUserRepository->addByType($accountId, $usersView);
                }

                if (!empty($usersEdit)) {
                    $this->accountToUserRepository->addByType($accountId, $usersEdit, true);
                }

                if (!empty($userGroupsView)) {
                    $this->accountToUserGroupRepository->addByType($accountId, $userGroupsView);
                }

                if (!empty($userGroupsEdit)) {
                    $this->accountToUserGroupRepository->addByType($accountId, $userGroupsEdit, true);
                }
            }
        }
    }

    /**
     * @param int[] $ids
     *
     * @return int[]
     * @throws ConstraintException
     * @throws QueryException
     */
    private function existingUsers(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->userRepository->getExistingIds($ids);
    }

    /**
     * @param int[] $ids
     *
     * @return int[]
     * @throws ConstraintException
     * @throws QueryException
     */
    private function existingUserGroups(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->userGroupRepository->getExistingIds($ids);
    }

}
