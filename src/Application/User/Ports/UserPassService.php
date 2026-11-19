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

use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\NoSuchItemException;

/**
 * Class UserPass
 */
interface UserPassService
{
    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function migrateUserPassById(int $id, string $userPass): void;

    /**
     * Replaces a login password's stored hash with a current one, changing nothing else
     *
     * For a password that has just been verified at sign-in. It is the same password, so unlike
     * a change it must not mark the password as changed — that would ask the user for their
     * previous password to re-key their master password at the next sign-in.
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function rehashUserPassById(int $id, string $userPass): void;
}
