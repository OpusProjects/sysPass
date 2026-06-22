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

namespace SP\Application\Crypt\Ports;

use PHPMailer\PHPMailer\Exception;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;

/**
 * Class TemporaryMasterPassService
 *
 * @package SP\Domain\Crypt\Services
 */
interface TemporaryMasterPassService
{
    /**
     * Creates a temporary key to encrypt the master password and store it.
     *
     * @param int $maxTime The maximum validity time of the key
     *
     * @return string
     * @throws ServiceException
     */
    public function create(int $maxTime = 14400): string;

    /**
     * Checks whether the temporary key is valid
     *
     * @param string $key key to check
     *
     * @return bool
     * @throws ServiceException
     */
    public function checkKey(string $key): bool;

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     * @throws Exception
     */
    public function sendByEmailForGroup(int $groupId, string $key): void;

    /**
     * @throws Exception
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    public function sendByEmailForAllUsers(string $key): void;

    /**
     * Returns the master password that was encrypted with the temporary key
     *
     * @param $key string with the key used to encrypt
     *
     * @return string with the decrypted master password
     * @throws NoSuchItemException
     * @throws ServiceException
     * @throws CryptException
     */
    public function getUsingKey(string $key): string;
}
