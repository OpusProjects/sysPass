<?php

declare(strict_types=1);

namespace SP\Domain\Crypt\Ports;

use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Exceptions\CryptException;

interface SessionKeyService
{
    /**
     * @throws CryptException
     */
    public function getSessionKey(SessionContext $sessionContext): string;

    /**
     * @throws CryptException
     */
    public function saveSessionKey(string $data, SessionContext $sessionContext): void;

    /**
     * @throws CryptException
     */
    public function reKey(SessionContext $sessionContext): void;
}
