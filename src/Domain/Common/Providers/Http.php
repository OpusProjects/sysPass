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

namespace SP\Domain\Common\Providers;

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Http\Ports\RequestService;

/**
 * Class Http
 */
final class Http
{
    /**
     * The HTTPS address this request should have been made to, or null if none is needed.
     *
     * This used to send the redirect itself, with a bare `header('Location: …')` — no status code,
     * no exit, no check that headers had already gone out. A `Location` on a 200 is not a redirect:
     * browsers ignore it, and execution carried on through the whole request, so the response the
     * setting was meant to prevent — an account page, an API token — was still built and sent over
     * the plaintext connection. "Force HTTPS" forced nothing.
     *
     * Answering with the address instead leaves the redirect to the caller, which is the only
     * place that can also stop the request: HttpModuleBase::redirectToHttpsIfRequired() sends it
     * through the router and throws, the way every other refusal in Init already does.
     */
    public static function httpsUrlFor(ConfigDataInterface $configData, RequestService $request): ?string
    {
        if (!$configData->isHttpsEnabled() || $request->isHttps()) {
            return null;
        }

        $serverPort = $request->getServerPort();
        $port = $serverPort !== 443 ? ':' . $serverPort : '';

        // Only the scheme. str_replace('http', 'https', …) rewrote every occurrence, so a host
        // with "http" in its name — http://httpbin.example — came back as https://httpsbin.example.
        $host = preg_replace('#^http://#i', 'https://', $request->getHttpHost()) ?? '';

        return sprintf('%s%s%s', $host, $port, $_SERVER['REQUEST_URI'] ?? '');
    }
}
