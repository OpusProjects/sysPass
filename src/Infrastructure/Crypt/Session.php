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

namespace SP\Infrastructure\Crypt;

use SP\Infrastructure\Context\SessionLifecycleHandler;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\SPException;

use function SP\logger;

/**
 * Class Session
 *
 * @package SP\Infrastructure\Crypt
 */
class Session
{
    /**
     * Returns the session master key
     *
     * @throws CryptException
     */
    public static function getSessionKey(SessionContext $sessionContext): string
    {
        $vault = $sessionContext->getVault()
            ?? throw new CryptException('Session vault not initialized');

        return $vault->getData(self::getKey($sessionContext));
    }

    private static function getKey(SessionContext $sessionContext): string
    {
        return self::buildSeed(session_id(), (string)$sessionContext->getSidStartTime());
    }

    private static function buildSeed(string ...$parts): string
    {
        return sha1(implode('', $parts));
    }

    /**
     * Save the master key in the session
     *
     * @throws CryptException
     */
    public static function saveSessionKey(string $data, SessionContext $sessionContext): void
    {
        $sessionContext->setVault(Vault::factory(new Crypt())->saveData($data, self::getKey($sessionContext)));
    }

    /**
     * Regenerate the session key
     *
     * Uses session_regenerate_id(true) rather than a commit+restart cycle so
     * that $_SESSION (and the context reference that aliases it) stays intact
     * throughout the operation.  The vault is then re-encrypted under the new
     * seed (new session ID + updated sidStartTime).
     *
     * @throws CryptException
     * @throws SPException
     */
    public static function reKey(SessionContext $sessionContext): void
    {
        logger(__METHOD__);

        $oldSeed = self::getKey($sessionContext);

        // Ensure a session is running, then regenerate its ID while keeping
        // the existing $_SESSION data (and therefore the vault reference) alive.
        SessionLifecycleHandler::start();
        session_regenerate_id(true);

        $newSeed = self::buildSeed(session_id(), (string)$sessionContext->setSidStartTime(time()));

        $vault = $sessionContext->getVault()
            ?? throw new CryptException('Session vault not initialized');

        $sessionContext->setVault($vault->reKey($newSeed, $oldSeed));
    }
}
