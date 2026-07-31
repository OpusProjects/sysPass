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

use SP\Domain\Common\Models\Item;

/**
 * A user or group an account is shared with, and whether that share grants editing.
 *
 * The permission queries select isEdit (and, for users, login) on top of the id/name every Item
 * carries. Mapping them onto Item itself made PDO assign undeclared properties through
 * Model::__set(), which throws "Dynamic properties not allowed" — so any account that was shared
 * with anyone could not be viewed at all. Extending Item keeps those extra columns off the generic
 * model while still satisfying the Item[] the account DTOs are typed against.
 */
class AccountPermissionItem extends Item
{
    protected ?string $login  = null;
    protected ?int    $isEdit = null;

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function getIsEdit(): ?int
    {
        return $this->isEdit;
    }
}
