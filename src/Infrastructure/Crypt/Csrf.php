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

use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Domain\Http\Method;
use SP\Domain\Http\Ports\RequestService;

use function SP\logger;

/**
 * Class Csrf
 *
 * The token is a random value held in the session and compared against the one the client
 * echoes back in the X-CSRF header. It is deliberately *not* derived from request data: an
 * earlier version hashed the User-Agent and client address, which meant the token carried no
 * per-session entropy (everyone behind one NAT with the same browser shared a token) and any
 * change to either value — the client address comes from the caller-supplied Forwarded /
 * X-Forwarded-For header — invalidated a still-valid session's token, rejecting every
 * state-changing request from then on.
 */
final readonly class Csrf implements CsrfHandler
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private SessionContext $context,
        private RequestService $request
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
            && ($sessionToken = $this->context->getCSRF()) !== null
            && ($method === Method::POST
                || ($method === Method::GET && $with === 'XMLHttpRequest'))
        ) {
            $token = $this->request->getHeader('X-CSRF');

            if (empty($token) || !hash_equals($sessionToken, $token)) {
                logger('Invalid CSRF token', 'ERROR');

                return false;
            }

            logger('CSRF token OK');
        }

        return true;
    }

    /**
     * Initialize the CSRF token
     */
    public function initialize(): void
    {
        if ($this->context->isLoggedIn()
            && $this->context->getCSRF() === null
        ) {
            $this->context->setCSRF(bin2hex(random_bytes(self::TOKEN_BYTES)));

            logger('CSRF token set');
        }
    }
}
