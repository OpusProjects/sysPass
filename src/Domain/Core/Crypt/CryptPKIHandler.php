<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Core\Crypt;

use SP\Domain\Core\Exceptions\FileException;

/**
 * Interface CryptPKIHandler
 */
interface CryptPKIHandler
{
    /**
     * Create the public and private key pair
     *
     * @throws FileException
     */
    public function createKeys(): void;

    /**
     * Return the public key from the file
     *
     * @throws FileException
     */
    public function getPublicKey(): string;

    /**
     * Decrypt data encrypted with the public key
     *
     * @throws FileException
     */
    public function decryptRSA(string $data): ?string;
}
