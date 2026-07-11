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
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Application\Account\Ports\AccountAclService;
use SP\Domain\Common\Models\Item;
use SP\Domain\Common\Services\Service;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Application\User\Ports\UserToUserGroupService;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\File\FileSystem;

use function SP\processException;

/**
 * Class AccountAcl
 */
final class AccountAcl extends Service implements AccountAclService
{
    private ?AccountAclDto     $accountAclDto     = null;
    private ?AccountPermission $accountPermission = null;
    private UserDto $userData;

    public function __construct(
        Application                             $application,
        private readonly AclInterface           $acl,
        private readonly UserToUserGroupService $userToUserGroupService,
        private readonly PathsContext $pathsContext,
        private readonly ?FileCacheService      $fileCache = null
    ) {
        parent::__construct($application);

        $this->userData = $this->context->getUserData();
    }

    /**
     * Get the ACL for an account
     *
     * @param int $actionId
     * @param AccountAclDto $accountAclDto
     * @param bool $isHistory
     *
     * @return AccountPermission
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getAcl(int $actionId, AccountAclDto $accountAclDto, bool $isHistory = false): AccountPermission
    {
        // Refresh userData from the context: in the API path the user is authenticated
        // inside setupApi() (called at action start), which runs after the DI container
        // has already constructed this service. Capturing userData only in the constructor
        // would see an unauthenticated (blank) UserDto for every API call.
        $this->userData = $this->context->getUserData();

        $this->accountPermission = new AccountPermission($actionId, $isHistory);
        $this->accountPermission->setShowPermission(
            self::getShowPermission($this->context->getUserData(), $this->context->getUserProfile())
        );

        $this->accountAclDto = $accountAclDto;

        if (null !== $this->fileCache) {
            $accountAcl = $this->getAclFromCache($accountAclDto->getAccountId(), $actionId);

            if (null !== $accountAcl) {
                $isModified = $accountAclDto->getDateEdit() > $accountAcl->getTime()
                              || (strtotime($this->userData->lastUpdate ?? '') ?: 0) > $accountAcl->getTime();

                if (!$isModified) {
                    $this->eventDispatcher->notify(new Event('get.acl', $this, EventMessage::build()->addDescription('Account ACL HIT')));

                    return $accountAcl;
                }

                $this->accountPermission->setModified(true);
            }
        }

        $this->eventDispatcher->notify(new Event('get.acl', $this, EventMessage::build()->addDescription('Account ACL MISS')));

        $this->accountPermission->setAccountId($accountAclDto->getAccountId());

        return $this->buildAcl();
    }

    /**
     * Sets grants which don't need the account's data
     *
     * @param UserDto $userData
     * @param ProfileData $profileData
     *
     * @return bool
     */
    public static function getShowPermission(UserDto $userData, ?ProfileData $profileData): bool
    {
        return $userData->isAdminApp
               || $userData->isAdminAcc
               || ($profileData?->isAccPermission() ?? false);
    }

    /**
     * Resturns an stored ACL
     *
     * @param int $accountId
     * @param int $actionId
     *
     * @return AccountPermission|null
     */
    public function getAclFromCache(int $accountId, int $actionId): ?AccountPermission
    {
        try {
            $acl = $this->fileCache->load($this->getCacheFileForAcl($accountId, $actionId));

            if ($acl instanceof AccountPermission) {
                return $acl;
            }
        } catch (FileException $e) {
            processException($e);
        }

        return null;
    }

    /**
     * @param int $accountId
     * @param int $actionId
     *
     * @return string
     */
    private function getCacheFileForAcl(int $accountId, int $actionId): string
    {
        $userId = $this->context->getUserData()->id ?? 0;

        return FileSystem::buildPath(
            $this->pathsContext[Path::CACHE],
            'accountAcl',
            (string)$userId,
            (string)$accountId,
            md5($userId . $accountId . $actionId),
            '.cache'
        );
    }

    /**
     * Create the ACL for an account
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    private function buildAcl(): AccountPermission
    {
        $this->compileAccountAccess();
        $this->accountPermission->setCompiledAccountAccess(true);

        $this->compileShowAccess();
        $this->accountPermission->setCompiledShowAccess(true);

        $this->accountPermission->setTime(time());

        $this->saveAclInCache($this->accountPermission);

        return $this->accountPermission;
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    private function compileAccountAccess(): void
    {
        $this->accountPermission->setResultView(false);
        $this->accountPermission->setResultEdit(false);

        // Check out if user is admin or owner/maingroup
        if ($this->userData->isAdminApp
            || $this->userData->isAdminAcc
            || $this->userData->id === $this->accountAclDto->getUserId()
            || $this->userData->userGroupId === $this->accountAclDto->getUserGroupId()
        ) {
            $this->accountPermission->setResultView(true);
            $this->accountPermission->setResultEdit(true);

            return;
        }

        // Check out if user is listed in secondary users of the account
        $userInUsers = $this->getUserInSecondaryUsers($this->userData->id ?? 0);
        $this->accountPermission->setUserInUsers(!empty($userInUsers));

        if ($this->accountPermission->isUserInUsers()) {
            $this->accountPermission->setResultView(true);
            $this->accountPermission->setResultEdit((int)$userInUsers[0]['isEdit'] === 1);

            return;
        }

        // Analyze user's groups
        // Groups in which the user is listed in
        $userGroups = array_map(
            static fn($value) => (int)$value->getUserGroupId(),
            $this->userToUserGroupService->getGroupsForUser($this->userData->id ?? 0)
        );

        // Check out if user groups match with account's main group
        if ($this->getUserGroupsInMainGroup($userGroups)) {
            $this->accountPermission->setUserInGroups(true);
            $this->accountPermission->setResultView(true);
            $this->accountPermission->setResultEdit(true);

            return;
        }

        // Check out if user groups match with account's secondary groups
        $userGroupsInSecondaryUserGroups =
            $this->getUserGroupsInSecondaryGroups(
                $userGroups,
                $this->userData->userGroupId ?? 0
            );

        $this->accountPermission->setUserInGroups(!empty($userGroupsInSecondaryUserGroups));

        if ($this->accountPermission->isUserInGroups()) {
            $this->accountPermission->setResultView(true);
            $this->accountPermission->setResultEdit((int)$userGroupsInSecondaryUserGroups[0]['isEdit'] === 1);
        }
    }

    /**
     * Checks if the user is listed in the account users
     *
     * @param int $userId
     *
     * @return Item[]
     */
    private function getUserInSecondaryUsers(int $userId): array
    {
        return array_values(
            array_filter(
                $this->accountAclDto->getUsersId(),
                static function ($value) use ($userId) {
                    return (int)$value->getId() === $userId;
                }
            )
        );
    }

    /**
     * Check whether the user's groups are linked through the account's main group
     *
     * @param int[] $userGroups
     *
     * @return bool
     */
    private function getUserGroupsInMainGroup(array $userGroups): bool
    {
        // Check whether the user is linked through the account's main group
        return in_array($this->accountAclDto->getUserGroupId(), $userGroups, true);
    }

    /**
     * Check whether the user or the user's group is among the groups associated with the
     * account.
     *
     * @param int[] $userGroups
     * @param int $userGroupId
     *
     * @return Item[]
     */
    private function getUserGroupsInSecondaryGroups(array $userGroups, int $userGroupId): array
    {
        $isAccountFullGroupAccess = $this->config->getConfigData()->isAccountFullGroupAccess();

        // Check whether the user's group is linked through the account's secondary groups
        return array_values(
            array_filter(
                $this->accountAclDto->getUserGroupsId(),
                static function ($value) use ($userGroupId, $isAccountFullGroupAccess, $userGroups) {
                    return (int)$value->getId() === $userGroupId
                           // or... allow groups other than the user's main group?
                           || ($isAccountFullGroupAccess
                               // Check whether the user is linked through the account's secondary groups
                               && in_array((int)$value->getId(), $userGroups, true));
                }
            )
        );
    }

    /**
     * compileShowAccess
     */
    private function compileShowAccess(): void
    {
        // Show history
        $this->accountPermission->setShowHistory(
            $this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_HISTORY_VIEW)
        );

        // Show file list
        $this->accountPermission->setShowFiles($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_FILE));

        // Show the view-password action
        $this->accountPermission->setShowViewPass($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW_PASS));

        // Show the edit action
        $this->accountPermission->setShowEdit($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_EDIT));

        // Show the edit-password action
        $this->accountPermission->setShowEditPass($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_EDIT_PASS));

        // Show the delete action
        $this->accountPermission->setShowDelete($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_DELETE));

        // Show the restore action
        $this->accountPermission->setShowRestore($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_EDIT));

        // Show the public link action
        $this->accountPermission->setShowLink($this->acl->checkUserAccess(AclActionsInterface::PUBLICLINK_CREATE));

        // Show the view-account action
        $this->accountPermission->setShowView($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW));

        // Show the copy-account action
        $this->accountPermission->setShowCopy($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_COPY));
    }

    /**
     * Saves the ACL
     *
     * @param AccountPermission $accountAcl
     *
     * @return void
     */
    private function saveAclInCache(AccountPermission $accountAcl): void
    {
        if (null === $this->fileCache) {
            return;
        }

        try {
            $this->fileCache->save(
                $accountAcl,
                $this->getCacheFileForAcl($accountAcl->getAccountId(), $accountAcl->getActionId())
            );
        } catch (FileException $e) {
            processException($e);
        }
    }
}
