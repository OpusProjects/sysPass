<?php

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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Bootstrap\Router;
use Symfony\Component\HttpFoundation\Request;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\HttpModuleBase;
use Psr\Log\LoggerInterface;
use SP\Domain\Core\LanguageInterface;
use SP\Infrastructure\Log\Providers\LogHandler;
use SP\Infrastructure\ProvidersHelper;
use SP\Tests\Support\UnitaryTestCase;

/**
 * "Force HTTPS" has to stop the request, not merely mention where it should have gone.
 *
 * It used to send a bare `header('Location: …')` with no status code and no exit, and then carry
 * on: the whole response — an account page on the web, a token on the API — was still built and
 * sent over the plaintext connection the setting exists to refuse. Browsers ignore a `Location` on
 * a 200, so nothing about it was visible either.
 *
 * The redirect and the halt are one thing, which is why they live together in the shared base both
 * entry points extend, and why these assert the throw rather than the header.
 */
#[Group('unitary')]
class HttpModuleBaseTest extends UnitaryTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function aPlaintextRequestIsRedirectedAndStopped(): void
    {
        $this->config->getConfigData()->setHttpsEnabled(true);

        $request = $this->createStub(RequestService::class);
        $request->method('isHttps')->willReturn(false);
        $request->method('getServerPort')->willReturn(443);
        $request->method('getHttpHost')->willReturn('http://vault.example');

        $_SERVER['REQUEST_URI'] = '/index.php?r=account/index';

        $response = $this->createMock(ResponseService::class);
        $response->expects(self::once())
                 ->method('redirect')
                 ->with('https://vault.example/index.php?r=account/index')
                 ->willReturnSelf();
        $response->expects(self::once())->method('send')->willReturnSelf();

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('HTTPS required');

        $this->moduleFor($request, $response)->redirectToHttps();
    }

    /**
     * A request that already arrived over HTTPS is left alone — nothing sent, nothing thrown.
     *
     * Without this the test above is satisfied by a base that refuses every request.
     *
     * @throws Exception
     * @throws InitializationException
     */
    #[Test]
    public function anHttpsRequestIsLeftAlone(): void
    {
        $this->config->getConfigData()->setHttpsEnabled(true);

        $request = $this->createStub(RequestService::class);
        $request->method('isHttps')->willReturn(true);

        // Asserted on the response rather than the router, which is final and cannot be doubled:
        // a redirect that is never built is a redirect that never happened.
        $response = $this->createMock(ResponseService::class);
        $response->expects(self::never())->method('redirect');
        $response->expects(self::never())->method('send');

        $this->moduleFor($request, $response)->redirectToHttps();
    }

    /**
     * And so is one on an installation that has not turned the setting on.
     *
     * @throws Exception
     * @throws InitializationException
     */
    #[Test]
    public function aPlaintextRequestIsLeftAloneWhenTheSettingIsOff(): void
    {
        $this->config->getConfigData()->setHttpsEnabled(false);

        $request = $this->createStub(RequestService::class);
        $request->method('isHttps')->willReturn(false);

        // Asserted on the response rather than the router, which is final and cannot be doubled:
        // a redirect that is never built is a redirect that never happened.
        $response = $this->createMock(ResponseService::class);
        $response->expects(self::never())->method('redirect');
        $response->expects(self::never())->method('send');

        $this->moduleFor($request, $response)->redirectToHttps();
    }

    /**
     * `HttpModuleBase` is abstract and its guard is protected — this is the smallest concrete thing
     * that can reach it, standing in for Web\Init and Api\Init, which differ in nothing that
     * matters here.
     *
     * @throws Exception
     */
    private function moduleFor(RequestService $request, ResponseService $response): object
    {
        return new class (
            $this->application,
            // ProvidersHelper and LogHandler are both final; neither is used by the guard under
            // test, but the base requires one, so it gets a real one over stubbed collaborators.
            new ProvidersHelper(
                new LogHandler(
                    $this->application,
                    $this->createStub(LoggerInterface::class),
                    $this->createStub(LanguageInterface::class),
                    $request
                )
            ),
            $request,
            new Router(new Request(), $response),
            $this->createStub(AppLockHandler::class)
        ) extends HttpModuleBase {
            public function initialize(string $controller): void
            {
            }

            public function getName(): string
            {
                return 'test';
            }

            /**
             * @throws InitializationException
             */
            public function redirectToHttps(): void
            {
                $this->redirectToHttpsIfRequired();
            }
        };
    }
}
