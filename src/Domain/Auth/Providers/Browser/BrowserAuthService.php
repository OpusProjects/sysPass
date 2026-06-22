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

namespace SP\Domain\Auth\Providers\Browser;

use SP\Domain\Auth\Providers\AuthService;

/**
 * Class Browser
 *
 * Authentication based on browser credentials
 *
 * @extends AuthService<BrowserAuthData>
 */
interface BrowserAuthService extends AuthService
{
    /**
     * Check whether the user is authenticated by the web server
     *
     * @param $login string The login of the user to check
     *
     * @return bool|null
     */
    public function checkServerAuthUser(string $login): ?bool;

    /**
     * Return the name of the user authenticated by the web server
     *
     * @return string|null
     */
    public function getServerAuthUser(): ?string;
}
