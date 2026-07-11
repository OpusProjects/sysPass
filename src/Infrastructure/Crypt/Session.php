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

use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Crypt\Vault;
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
class Session implements SessionKeyService
{
    /**
     * @throws CryptException
     */
    public function getSessionKey(SessionContext $sessionContext): string
    {
        $vault = $sessionContext->getVault()
            ?? throw new CryptException('Session vault not initialized');

        return $vault->getData(self::buildKey($sessionContext));
    }

    private static function buildKey(SessionContext $sessionContext): string
    {
        return self::buildSeed(session_id(), (string)$sessionContext->getSidStartTime());
    }

    private static function buildSeed(string ...$parts): string
    {
        return sha1(implode('', $parts));
    }

    /**
     * @throws CryptException
     */
    public function saveSessionKey(string $data, SessionContext $sessionContext): void
    {
        $sessionContext->setVault(Vault::factory(new Crypt())->saveData($data, self::buildKey($sessionContext)));
    }

    /**
     * @throws CryptException
     * @throws SPException
     */
    public function reKey(SessionContext $sessionContext): void
    {
        logger(__METHOD__);

        $oldSeed = self::buildKey($sessionContext);

        SessionLifecycleHandler::start();
        session_regenerate_id(true);

        $newSeed = self::buildSeed(session_id(), (string)$sessionContext->setSidStartTime(time()));

        $vault = $sessionContext->getVault()
            ?? throw new CryptException('Session vault not initialized');

        $sessionContext->setVault($vault->reKey($newSeed, $oldSeed));
    }
}
