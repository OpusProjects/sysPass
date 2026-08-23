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

namespace SP\Tests\Unit\Infrastructure\Bootstrap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class RouterTest
 *
 * Covers the three ways dispatch() can end once its try block runs: nothing matched at all, a
 * responder threw and an error handler was registered, and a responder threw with no error handler
 * registered. All three pass $sendResponse = false so the test does not also have to fake a working
 * ResponseService::send()/isSent() pair — a bare stub is enough since neither is exercised.
 */
#[Group('unitary')]
class RouterTest extends UnitaryTestCase
{
    /**
     * A request that matches no registered responder at all (the Symfony matcher's
     * ResourceNotFoundException) must not escape dispatch(), and — this is the part that isolates
     * this specific catch from the generic Throwable one right after it — it must not be handed to
     * a registered error handler either. If the routing miss fell into the Throwable catch instead,
     * an application's onError() page would render for every request whose route simply does not
     * exist, rather than letting it fall through to whatever comes next.
     */
    #[Test]
    public function anUnmatchedRequestFallsThroughWithoutReachingTheErrorHandler(): void
    {
        $request = Request::create('/', 'GET');
        $router = new Router($request, $this->createStub(ResponseService::class));

        // No responder is registered at all, so the Symfony matcher always misses.
        $onErrorCalled = false;
        $router->onError(function () use (&$onErrorCalled): void {
            $onErrorCalled = true;
        });

        $router->dispatch($request, $this->createStub(ResponseService::class), false);

        self::assertFalse(
            $onErrorCalled,
            'a request nothing responds to must fall through silently, not be treated as a responder error'
        );
    }

    /**
     * When a registered responder throws something other than a routing miss, and an error handler
     * was registered via onError(), dispatch() hands the throwable to it — message, exception class
     * and the throwable itself — rather than letting it propagate. This is what lets the
     * application turn an unexpected exception into a rendered error response instead of a fatal
     * one escaping to the caller.
     */
    #[Test]
    public function aResponderExceptionIsHandedToTheRegisteredErrorHandler(): void
    {
        $request = Request::create('/', 'GET');
        $router = new Router($request, $this->createStub(ResponseService::class));

        $router->respond('GET', null, function (): void {
            throw new RuntimeException('boom');
        });

        $caught = [];
        $router->onError(
            function ($calledRouter, $message, $exceptionClass, $throwable) use (&$caught, $router): void {
                $caught = [$calledRouter, $message, $exceptionClass, $throwable];
            }
        );

        $router->dispatch($request, $this->createStub(ResponseService::class), false);

        self::assertSame($router, $caught[0], 'the router hands itself to its own error handler');
        self::assertSame('boom', $caught[1]);
        self::assertSame(RuntimeException::class, $caught[2]);
        self::assertInstanceOf(RuntimeException::class, $caught[3]);
        self::assertSame('boom', $caught[3]->getMessage());
    }

    /**
     * With no error handler registered, a responder's exception must reach the caller of
     * dispatch() rather than being swallowed — an application that never calls onError() still
     * needs to see its own bugs surface as an exception, not as a silently empty response.
     */
    #[Test]
    public function aResponderExceptionIsRethrownWhenNoErrorHandlerIsRegistered(): void
    {
        $request = Request::create('/', 'GET');
        $router = new Router($request, $this->createStub(ResponseService::class));

        $router->respond('GET', null, function (): void {
            throw new RuntimeException('kaboom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kaboom');

        $router->dispatch($request, $this->createStub(ResponseService::class), false);
    }
}
