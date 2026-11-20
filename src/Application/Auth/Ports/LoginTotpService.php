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

use SP\Domain\Auth\Dtos\TotpProof;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\User\Dtos\UserDto;

/**
 * The second factor at sign-in
 */
interface LoginTotpService
{
    /**
     * Check the code sent with the sign-in, for a user who has a second factor or is required to
     *
     * A one-time code or one of the user's recovery codes; for a user who is required to have a
     * second factor and has none, a code from the secret they are being asked to enrol.
     *
     * @return TotpProof|null What to spend once sign-in succeeds, or null when nothing is needed
     * @throws AuthException When the code is missing or wrong, or enrolment is needed
     */
    public function check(UserDto $user): ?TotpProof;

    /**
     * Spend what check() accepted, at the point sign-in is about to succeed
     *
     * @return string[] The recovery codes, when spending it completed an enrolment
     * @throws AuthException When another sign-in spent it first
     */
    public function consume(int $userId, TotpProof $proof): array;
}
