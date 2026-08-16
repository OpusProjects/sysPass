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

namespace SP\Domain\Account\Models;

/**
 * Class AccountHistoryView
 *
 * An AccountHistory row enriched with the owner's, the group's and the editor's names for display
 * purposes. The row itself stores only ids, and the detail view shows all three by name — the same
 * three the current account's view shows, which reads them from `account_data_v`.
 */
final class AccountHistoryView extends AccountHistory
{
    protected ?string $userName      = null;
    protected ?string $userGroupName = null;
    protected ?string $userEditName  = null;
    protected ?string $userEditLogin = null;

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getUserGroupName(): ?string
    {
        return $this->userGroupName;
    }

    public function getUserEditName(): ?string
    {
        return $this->userEditName;
    }

    public function getUserEditLogin(): ?string
    {
        return $this->userEditLogin;
    }
}
