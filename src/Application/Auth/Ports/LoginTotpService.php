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

namespace SP\Application\Auth\Ports;

use SP\Domain\Auth\Services\AuthException;

/**
 * The second factor at sign-in
 */
interface LoginTotpService
{
    /**
     * Check the code sent with the sign-in, for a user who has a second factor
     *
     * @return int|null The step to spend once sign-in succeeds, or null when the user has none
     * @throws AuthException When the code is missing or wrong
     */
    public function check(int $userId): ?int;

    /**
     * Spend the step check() accepted, at the point sign-in is about to succeed
     *
     * @throws AuthException When another sign-in spent it first
     */
    public function consume(int $userId, int $step): void;
}
