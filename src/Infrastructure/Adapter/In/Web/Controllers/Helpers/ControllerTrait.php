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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Helpers;

use Closure;
use SP\Core\Bootstrap\Router;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Dtos\JsonMessage;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Http\Providers\Uri;
use SP\Domain\Http\Services\JsonResponse;

use function SP\__u;
use function SP\processException;

/**
 * Trait ControllerTrait
 */
trait ControllerTrait
{
    protected Router $router;

    /**
     * Logout from current session
     *
     * @param RequestService $request
     * @param ConfigDataInterface $configData
     * @param Closure $onRedirect
     *
     * @throws SPException
     */
    protected function sessionLogout(
        RequestService $request,
        ConfigDataInterface $configData,
        Closure        $onRedirect
    ): void {
        if ($request->isJson()) {
            $jsonResponse = new JsonMessage(__u('Session not started or timed out'));
            $jsonResponse->setStatus(10);

            JsonResponse::factory($this->router->response())->send($jsonResponse);
        } elseif ($request->isAjax()) {
            self::logout();
        } else {
            try {
                // Analyzes if there is any direct route within the URL
                // then it computes the route HMAC to build a signed URI
                // which would be used during logging in
                $route = $request->analyzeString('r');
                $hash = $request->analyzeString('h');

                $uri = new Uri($this->uriContext->getWebRoot() . $this->uriContext->getSubUri());
                $uri->addParam('_r', 'login');

                if ($route && $hash) {
                    $key = $configData->getPasswordSalt() ?? '';
                    $request->verifySignature($key);

                    $uri->addParam('from', $route);

                    $onRedirect->call($this, $uri->getUriSigned($key));
                } else {
                    $onRedirect->call($this, $uri->getUri());
                }
            } catch (SPException $e) {
                processException($e);
            }
        }
    }

    /**
     * Performs the logout process.
     */
    private static function logout(): never
    {
        exit('<script>sysPassApp.actions.main.logout();</script>');
    }

}
