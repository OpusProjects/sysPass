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

namespace SP\Domain\User\Ports;

use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\User\Models\UserTotp as UserTotpModel;

/**
 * Storage for users' one-time password secrets
 *
 * @template T of UserTotpModel
 */
interface UserTotpRepository
{
    /**
     * The user's row, enabled or pending
     *
     * @param int $userId
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getByUserId(int $userId): QueryResult;

    /**
     * Store a pending enrolment, replacing whatever the user had
     *
     * @param UserTotpModel $userTotp
     *
     * @return int The affected rows
     * @throws ConstraintException
     * @throws QueryException
     */
    public function replace(UserTotpModel $userTotp): int;

    /**
     * Confirm a pending enrolment with the step of the code that confirmed it
     *
     * Only a pending row is matched, so confirming twice, or confirming a row that was replaced
     * meanwhile, affects nothing.
     *
     * @param int $userId
     * @param int $step
     *
     * @return int The affected rows: 0 when there was no pending enrolment to confirm
     * @throws ConstraintException
     * @throws QueryException
     */
    public function enable(int $userId, int $step): int;

    /**
     * Record a code's step as used, if no later or equal step has been
     *
     * The check and the write are one statement: two sign-ins presenting the same code at once
     * cannot both see the step as unused.
     *
     * @param int $userId
     * @param int $step
     *
     * @return int The affected rows: 0 when the step, or a later one, was already used
     * @throws ConstraintException
     * @throws QueryException
     */
    public function consumeStep(int $userId, int $step): int;

    /**
     * Remove the user's secret, enabled or pending
     *
     * @param int $userId
     *
     * @return int The affected rows
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByUserId(int $userId): int;
}
