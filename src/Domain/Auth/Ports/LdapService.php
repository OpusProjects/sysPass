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

namespace SP\Domain\Auth\Ports;

use SP\Domain\Auth\Providers\Ldap\LdapException;

/**
 * Interface LdapService
 */
interface LdapService
{
    /**
     * Get the filter to search for the user
     *
     * @param string $userLogin
     *
     * @return string
     */
    public function getUserDnFilter(string $userLogin): string;

    /**
     * Return the filter to check the group membership from user's attributes
     *
     * @return string
     */
    public function getGroupMembershipIndirectFilter(): string;

    /**
     * Return the filter to check the group membership from group's attributes
     *
     * @param string|null $userDn
     *
     * @return string
     */
    public function getGroupMembershipDirectFilter(?string $userDn = null): string;

    /**
     * Search for the user in a group.
     *
     * @param string $userDn
     * @param string $userLogin
     * @param string[] $groupsDn
     *
     * @return bool
     */
    public function isUserInGroup(string $userDn, string $userLogin, array $groupsDn): bool;

    /**
     * Return the filter for objects of the group type
     *
     * @return string
     */
    public function getGroupObjectFilter(): string;

    /**
     * @param string|null $bindDn
     * @param string|null $bindPass
     *
     * @throws LdapException
     **/
    public function connect(?string $bindDn = null, ?string $bindPass = null): void;

    /**
     * @return LdapActionsService
     */
    public function actions(): LdapActionsService;

    /**
     * @return string
     */
    public function getServer(): string;
}
