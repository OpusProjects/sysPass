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

use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;

/**
 * Storage for the recovery codes of users' second factors
 */
interface UserTotpRecoveryRepository
{
    /**
     * Replace every code the user had with these
     *
     * @param int $userId
     * @param string[] $hashes
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function replace(int $userId, array $hashes): void;

    /**
     * How many unused codes the user has, or whether this one is among them when a hash is given
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function countUnused(int $userId, ?string $hash = null): int;

    /**
     * Spend a code: the check that it is unused and the write are one statement
     *
     * @return int The affected rows: 0 when the code is unknown or already used
     * @throws ConstraintException
     * @throws QueryException
     */
    public function consume(int $userId, string $hash): int;

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByUserId(int $userId): int;
}
