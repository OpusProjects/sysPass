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

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Infrastructure\Http\Method;
use SP\Infrastructure\Http\Ports\RequestService;

use function SP\logger;

/**
 * Class Csrf
 */
final readonly class Csrf implements CsrfHandler
{

    public function __construct(
        private SessionContext      $context,
        private RequestService      $request,
        private ConfigDataInterface $configData
    ) {
    }

    /**
     * Check for CSRF token on POST requests
     */
    public function check(): bool
    {
        $method = $this->request->getMethod();
        $with = $this->request->getHeader('X-Requested-With');

        if ($this->context->isLoggedIn()
            && $this->context->getCSRF() !== null
            && ($method === Method::POST
                || ($method === Method::GET && $with === 'XMLHttpRequest'))
        ) {
            $token = $this->request->getHeader('X-CSRF');

            if (empty($token)
                || !Hash::checkMessage($this->getKey(), $this->configData->getPasswordSalt() ?? '', $token)
            ) {
                logger('Invalid CSRF token', 'ERROR');

                return false;
            }

            logger('CSRF token OK');
        }

        return true;
    }

    /**
     * Returns the encryption key for the cookie data
     */
    private function getKey(): string
    {
        return sha1(sprintf("%s%s", $this->request->getHeader('User-Agent'), $this->request->getClientAddress()));
    }

    /**
     * Initialize the CSRF key
     */
    public function initialize(): void
    {
        if ($this->context->isLoggedIn()
            && $this->context->getCSRF() === null
        ) {
            $key = Hash::signMessage($this->getKey(), $this->configData->getPasswordSalt() ?? '');

            $this->context->setCSRF($key);

            logger('CSRF key set');
        }
    }
}
