<?php
/*
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account;

use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;

/**
 * Enforces per-account (object level) authorization for the account mutation
 * endpoints (delete/edit/edit-pass/restore).
 *
 * The render controllers gate every account through
 * AccountHelper::checkAccess(); the mutation controllers used to call the
 * service directly with no such check, allowing an authenticated user to act on
 * any account by id (IDOR). This collaborator mirrors that same check so it can
 * be reused consistently by every executor controller.
 */
final class AccountAclEnforcer
{
    public function __construct(
        private readonly AccountService    $accountService,
        private readonly AccountAclService $accountAclService
    ) {
    }

    /**
     * Ensures the current user has access to the given account for the given
     * action, throwing when they don't.
     *
     * @param int $actionId One of the ACCOUNT_* ACL action ids
     * @param int $accountId The account being acted upon
     *
     * @throws AccountPermissionException When the user isn't allowed on the account
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function checkAccountAccess(int $actionId, int $accountId): void
    {
        $accountEnrichedDto = $this->accountService->withUserGroups(
            $this->accountService->withUsers(
                new AccountEnrichedDto($this->accountService->getByIdEnriched($accountId))
            )
        );

        $accountPermission = $this->accountAclService->getAcl(
            $actionId,
            AccountAclDto::makeFromAccount($accountEnrichedDto)
        );

        if ($accountPermission->checkAccountAccess($actionId) === false) {
            throw new AccountPermissionException(SPException::INFO);
        }
    }
}
