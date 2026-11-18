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

use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;

/**
 * Class AccountFileAcl
 *
 * Object-level authorization guard for account file operations. File access is derived from the
 * access the current user has to the owning account: read operations (view/download/list) require
 * view access, write operations (upload/delete) require edit access. This closes the IDOR where any
 * authenticated user could act on files belonging to accounts they can't access.
 *
 * Both also require the profile's "Files" permission (ACCOUNT_FILE). That permission used to be
 * read only to decide whether the Files tab is shown, so a user without it could still list,
 * download, upload and delete files by calling the routes directly. It is checked here, the one
 * place every file operation on both the web and the API reaches, rather than in each controller.
 */
final class AccountFileAcl
{
    public function __construct(
        private readonly AccountService    $accountService,
        private readonly AccountAclService $accountAclService,
        private readonly AclInterface      $acl
    ) {
    }

    /**
     * Ensures the current user can view the given account (required to view/download/list its files).
     *
     * @throws AccountPermissionException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function requireView(int $accountId): void
    {
        $this->check($accountId, AclActionsInterface::ACCOUNT_VIEW);
    }

    /**
     * Ensures the current user can edit the given account (required to upload/delete its files).
     *
     * @throws AccountPermissionException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function requireEdit(int $accountId): void
    {
        $this->check($accountId, AclActionsInterface::ACCOUNT_EDIT);
    }

    /**
     * @throws AccountPermissionException
     * @throws ConstraintException
     * @throws QueryException
     */
    private function check(int $accountId, int $actionId): void
    {
        // Step 1: the profile must grant file access at all. Checked before the account is read,
        // so a refusal here says nothing about whether the account exists.
        if ($this->acl->checkUserAccess(AclActionsInterface::ACCOUNT_FILE) === false) {
            throw new AccountPermissionException();
        }

        // Step 2: the user must have the required access to the account that owns the file.
        $accountEnrichedDto = new AccountEnrichedDto($this->accountService->getByIdEnriched($accountId));
        $accountEnrichedDto = $this->accountService->withUsers($accountEnrichedDto);
        $accountEnrichedDto = $this->accountService->withUserGroups($accountEnrichedDto);

        $permission = $this->accountAclService->getAcl(
            $actionId,
            AccountAclDto::makeFromAccount($accountEnrichedDto)
        );

        if ($permission->checkAccountAccess($actionId) === false) {
            throw new AccountPermissionException();
        }
    }
}
