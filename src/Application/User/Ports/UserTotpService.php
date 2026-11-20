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

namespace SP\Application\User\Ports;

use SP\Domain\Common\Services\ServiceException;

/**
 * A user's second factor: enrolment, verification at sign-in, removal
 */
interface UserTotpService
{
    /**
     * Whether sign-in has to ask this user for a code
     *
     * @throws ServiceException
     */
    public function isEnabled(int $userId): bool;

    /**
     * Check a code against the user's confirmed secret, without spending it
     *
     * @return int|null The time step the code belongs to, or null when it is not valid now
     * @throws ServiceException
     */
    public function verify(int $userId, string $code, ?int $time = null): ?int;

    /**
     * Spend a verified code's step, so that neither it nor an earlier one is accepted again
     *
     * @return bool False when the step, or a later one, was spent already
     * @throws ServiceException
     */
    public function consume(int $userId, int $step): bool;

    /**
     * Generate and store a new secret, pending until a code from it is confirmed
     *
     * @return string The secret, base32-encoded, for the authenticator app
     * @throws ServiceException When the user already has a confirmed one
     */
    public function startEnrolment(int $userId): string;

    /**
     * Turn a pending enrolment on, given a code generated from its secret
     *
     * @return bool False when the code does not match or nothing is pending
     * @throws ServiceException
     */
    public function confirmEnrolment(int $userId, string $code, ?int $time = null): bool;

    /**
     * Remove the user's secret, confirmed or pending
     *
     * @throws ServiceException
     */
    public function disable(int $userId): void;
}
