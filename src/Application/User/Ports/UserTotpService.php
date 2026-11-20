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

use SP\Domain\Auth\Dtos\TotpProof;
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
     * Remove the user's secret, confirmed or pending, and their recovery codes
     *
     * @throws ServiceException
     */
    public function disable(int $userId): void;

    /**
     * Check what the user typed as their second factor — a one-time code or a recovery code —
     * without spending it
     *
     * @return TotpProof|null What to spend once the operation it guards succeeds, or null when it
     *                        is not valid now
     * @throws ServiceException
     */
    public function check(int $userId, string $typed, ?int $time = null): ?TotpProof;

    /**
     * Spend what check() or checkPending() accepted; spending an enrolment's code confirms it
     *
     * @return bool False when another request spent it first
     * @throws ServiceException
     */
    public function spend(int $userId, TotpProof $proof): bool;

    /**
     * The secret of the user's unconfirmed enrolment, if there is one
     *
     * Sign-in shows it again rather than starting another, so a user who is asked to enrol and
     * tries twice does not find the code they already scanned has been replaced.
     *
     * @throws ServiceException
     */
    public function pendingSecret(int $userId): ?string;

    /**
     * Check a code against the user's unconfirmed enrolment, without confirming it
     *
     * @return TotpProof|null What to spend — which confirms the enrolment — or null
     * @throws ServiceException
     */
    public function checkPending(int $userId, string $code, ?int $time = null): ?TotpProof;

    /**
     * Replace the user's recovery codes with a new set
     *
     * @return string[] The codes, to be shown once: only their hashes are kept
     * @throws ServiceException When the user has no confirmed second factor
     */
    public function generateRecoveryCodes(int $userId): array;

    /**
     * How many of the user's recovery codes are unused
     *
     * @throws ServiceException
     */
    public function countRecoveryCodes(int $userId): int;
}
