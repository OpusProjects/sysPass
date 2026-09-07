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

namespace SP\Infrastructure\Bootstrap;

use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Http\Ports\RequestService;

/**
 * Class UriContext
 */
final readonly class UriContext implements UriContextInterface
{
    private string $subUri;
    private string $webRoot;
    private string $webUri;
    private string $unforwardedWebUri;

    public function __construct(RequestService $request)
    {
        $this->subUri = $this->buildSubUri($request);
        $this->webRoot = $this->buildWebRoot($request);
        $this->webUri = $request->getHttpHost() . $this->webRoot;
        $this->unforwardedWebUri = $request->getHttpHostIgnoringForwarding() . $this->webRoot;
    }

    private function buildSubUri(RequestService $request): string
    {
        return '/' . basename($request->getServer('SCRIPT_FILENAME'));
    }

    private function buildWebRoot(RequestService $request): string
    {
        $uri = $request->getServer('REQUEST_URI');

        $pos = strpos($uri, $this->subUri);

        if ($pos > 0) {
            return substr($uri, 0, $pos);
        }

        return '';
    }

    /**
     * The same URI, built without consulting `Forwarded` / `X-Forwarded-*`.
     *
     * `getWebUri()` prefers those headers so that an installation behind a reverse proxy reports
     * the address its users actually type. They are supplied by whoever made the request, though,
     * and nothing here calls `setTrustedProxies()` — verified against the running instance, where
     * `X-Forwarded-Host: evil.example.com` comes straight back out. That is the right trade for a
     * displayed URL and the wrong one for a link that is mailed to somebody else and carries a
     * one-time token, which is what this exists for.
     */
    public function getUnforwardedWebUri(): string
    {
        return $this->unforwardedWebUri;
    }

    public function getWebUri(): string
    {
        return $this->webUri;
    }

    public function getWebRoot(): string
    {
        return $this->webRoot;
    }

    public function getSubUri(): string
    {
        return $this->subUri;
    }
}
