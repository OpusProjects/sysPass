<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Application\Account\Ports;

use SP\Domain\Account\Dtos\AccountDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\NoSuchPropertyException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\ValidationException;

/**
 * Class AccountPreset
 *
 * @package SP\Domain\Account\Services
 */
interface AccountPresetService
{
    /**
     * @throws ValidationException
     * @throws ConstraintException
     * @throws NoSuchPropertyException
     * @throws QueryException
     */
    public function checkPasswordPreset(AccountDto $accountDto): AccountDto;

    /**
     * Holds an account to the policy's password lifetime, without asking about the password.
     *
     * For the paths that change an account without setting a password: they write
     * `passDateChange` from a field the form offers, and the cap a fixed preset sets is a maximum
     * that has to survive an edit. Separate from `checkPasswordPreset()` because that validates
     * the password too, and an edit legitimately carries none.
     *
     * @template T of AccountDto
     * @param T $accountDto
     * @return T
     * @throws ConstraintException
     * @throws QueryException
     */
    public function checkPasswordExpiry(AccountDto $accountDto): AccountDto;

    /**
     * @throws QueryException
     * @throws ConstraintException
     * @throws NoSuchPropertyException
     */
    public function addPresetPermissions(int $accountId): void;
}
