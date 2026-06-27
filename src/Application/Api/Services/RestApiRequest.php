<?php

declare(strict_types=1);
/*
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

namespace SP\Application\Api\Services;

use SP\Application\Api\Ports\ApiRequestService;
use SP\Domain\Api\Dtos\ApiRequestData;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API request adapter.
 *
 * Merges parameters from URL path attributes, query string, and JSON body
 * into a single data bag so that existing controllers (which read params
 * via ApiService::getParam*) work without changes.
 *
 * Auth token is extracted from the Authorization: Bearer header.
 */
final class RestApiRequest implements ApiRequestService
{
    private ?string $method = null;
    private ?ApiRequestData $data = null;

    private function __construct()
    {
    }

    public static function buildFromRequest(string $stream = self::PHP_REQUEST_STREAM): ApiRequestService
    {
        return self::buildFromSymfonyRequest(Request::createFromGlobals());
    }

    public static function buildFromSymfonyRequest(Request $request): ApiRequestService
    {
        $restRequest = new self();

        $params = [];

        // Query string params (GET filters/search)
        $params = array_merge($params, $request->query->all());

        // JSON body (POST/PUT)
        $content = $request->getContent();
        if (!empty($content)) {
            $body = json_decode($content, true);
            if (is_array($body)) {
                $params = array_merge($params, $body);
            }
        }

        // Route attributes (id, etc.) — set by Router::dispatch()
        foreach ($request->attributes->all() as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $params[$key] = $value;
            }
        }

        // Authorization: Bearer <token>
        $authHeader = $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $params['authToken'] = $matches[1];
        }

        $restRequest->data = new ApiRequestData($params);
        $restRequest->method = $request->attributes->get('_rest_method', '');

        return $restRequest;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data->get($key, $default);
    }

    public function exists(string $key): bool
    {
        return $this->data->exists($key);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getId(): int
    {
        return 0;
    }
}
