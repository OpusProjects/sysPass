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
     * Plain navigations a signed-in browser makes without the token header, as "controller/action"
     * in lower case. None of them changes anything but logout, which only ends the session.
     *
     * Everything else a signed-in user does goes through the front end's request helper, which
     * sends the token on every call; these are the ones that cannot: the page itself, the links an
     * email or a public link hands out, and the downloads the browser opens directly.
     */
    private const PLAIN_NAVIGATIONS = [
        'index/index',
        'login/index',
        'login/logout',
        'userpassreset/index',
        'userpassreset/reset',
        'account/viewlink',
        'accountfile/download',
        'accountfile/view',
        'configbackup/downloadbackupapp',
        'configbackup/downloadbackupdb',
        'configbackup/downloadexport',
    ];

    /**
     * Check the CSRF token on every request from a signed-in user
     *
     * It used to be checked on a POST and on a GET marked as XMLHttpRequest only, so a plain GET —
     * a link, an image, a redirect — carried the session cookie and nothing else, and reached any
     * action: `?r=eventlog/clear`, `?r=category/delete/5`, a save with its fields in the query
     * string. The session cookie is SameSite=Strict, which keeps a cross-site page from sending
     * it, so what was left open was any same-site origin: a stored script, a sibling subdomain.
     *
     * Now the token is required whatever the method, and the only requests let through without it
     * are a plain GET to one of the navigations above.
     */
    public function check(): bool
    {
        // Step 1: nothing to compare against before login, or before the first token is issued.
        if (!$this->context->isLoggedIn() || ($sessionToken = $this->context->getCSRF()) === null) {
            return true;
        }

        // Step 2: a request that carries the token is checked on it, whatever its method.
        $token = $this->request->getHeader('X-CSRF');

        if ($token !== '') {
            if (hash_equals($sessionToken, $token)) {
                logger('CSRF token OK');

                return true;
            }

            logger('Invalid CSRF token', 'ERROR');

            return false;
        }

        // Step 3: one without it may only be a plain GET to a navigation.
        if ($this->request->getMethod() === Method::GET && $this->isPlainNavigation()) {
            return true;
        }

        logger('Missing CSRF token', 'ERROR');

        return false;
    }

    /**
     * Whether the request's route is one of the navigations a browser makes without the token
     */
    private function isPlainNavigation(): bool
    {
        $route = strtolower(trim($this->request->analyzeString('r', '') ?? '', '/'));
        [$controller, $action] = array_pad(explode('/', $route, 3), 2, '');

        return in_array(
            sprintf('%s/%s', $controller ?: 'index', $action ?: 'index'),
            self::PLAIN_NAVIGATIONS,
            true
        );
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
