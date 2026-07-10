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

namespace SP\Core\Bootstrap;

use Closure;
use SP\Domain\Http\Ports\ResponseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

/**
 * Class Router
 *
 * Minimal HTTP dispatcher backed by Symfony Routing. Replaces the abandoned
 * third-party router while preserving the small surface the application relied
 * on: registering catch-all responders and accessing the current request/response.
 *
 * The application resolves the actual controller/action from the `r` request
 * parameter (see {@link RouteContext}); the routes registered here are method
 * scoped catch-alls, matching the previous router's behaviour.
 */
final class Router
{
    private readonly RouteCollection $routes;
    private int      $routeCount = 0;
    private ?Closure $onError    = null;

    public function __construct(
        private Request $request,
        private readonly ResponseService $response
    ) {
        $this->routes = new RouteCollection();
    }

    /**
     * Register a responder for the given HTTP method(s).
     *
     * The route pattern is intentionally a method-scoped catch-all: routing to a
     * concrete controller/action happens later from the `r` parameter.
     *
     * @param string|string[] $methods
     */
    public function respond(string|array $methods, ?string $route, callable $callback): void
    {
        $route = new Route(
            '/{req}',
            ['req' => '', '_callback' => $callback(...)],
            ['req' => '.*'],
            [],
            '',
            [],
            array_map(strtoupper(...), (array)$methods)
        );

        $this->routes->add('route_' . $this->routeCount++, $route);
    }

    /**
     * Register a responder for a specific URL path with named parameters.
     *
     * Unlike {@see respond()}, this registers an actual path (e.g. `/api/v1/accounts/{id}`)
     * instead of a catch-all. Named parameters are injected into the request attributes
     * during dispatch.
     *
     * @param string|string[] $methods
     * @param array<string,string> $requirements Regex constraints for path parameters
     */
    public function respondPath(string|array $methods, string $path, callable $callback, array $requirements = []): void
    {
        $route = new Route(
            $path,
            ['_callback' => $callback(...)],
            $requirements,
            [],
            '',
            [],
            array_map(strtoupper(...), (array)$methods)
        );

        $this->routes->add('route_' . $this->routeCount++, $route);
    }

    /**
     * Set the error handler, invoked as ($router, $message, $exceptionClass, $throwable).
     */
    public function onError(callable $callback): void
    {
        $this->onError = $callback(...);
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function response(): ResponseService
    {
        return $this->response;
    }

    /**
     * Match the request against the registered responders and run the first one
     * whose method matches, then optionally send the response.
     */
    public function dispatch(?Request $request = null, ?ResponseService $response = null, bool $sendResponse = true): void
    {
        $request ??= $this->request;
        $this->request = $request;
        $response ??= $this->response;

        $context = new RequestContext();
        $context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $context);

        // Wrap dispatch in an output buffer so responders that stream output
        // directly (e.g. FileHandler::readChunked() print + ob_flush) are
        // captured and flushed downstream as a unit, instead of escaping any
        // surrounding buffer.
        ob_start();
        $bufferLevel = ob_get_level();

        try {
            try {
                $parameters = $matcher->match($request->getPathInfo());

                foreach ($parameters as $key => $value) {
                    if (!str_starts_with($key, '_')) {
                        $request->attributes->set($key, $value);
                    }
                }

                /** @var Closure $callback */
                $callback = $parameters['_callback'];
                $callback($request, $response);
            } catch (ResourceNotFoundException | MethodNotAllowedException) {
                // No responder matched (e.g. an unsupported method); fall through.
            } catch (Throwable $e) {
                if ($this->onError !== null) {
                    ($this->onError)($this, $e->getMessage(), $e::class, $e);
                } else {
                    throw $e;
                }
            }

            if ($sendResponse && !$response->isSent()) {
                $response->send(true);
            }
        } finally {
            while (ob_get_level() >= $bufferLevel) {
                ob_end_flush();
            }
        }
    }
}
