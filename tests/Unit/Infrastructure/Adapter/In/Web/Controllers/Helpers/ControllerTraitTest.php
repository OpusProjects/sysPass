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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Http\Providers\Uri;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ControllerTrait;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use Symfony\Component\HttpFoundation\Request;
use SP\Tests\Support\UnitaryTestCase;

/**
 * What every controller does when the session has gone: the browser has to be sent back to the
 * login page, and the request it was making has to survive the round trip so the user lands where
 * they were going.
 *
 * That last part is the reason this is not a one-liner. The route is carried across in the query
 * string, so it is signed on the way out and its signature verified on the way in — otherwise
 * anybody could hand a user a link that signs them in and drops them somewhere chosen by the
 * sender.
 */
#[Group('unitary')]
class ControllerTraitTest extends UnitaryTestCase
{
    private const SALT = 'the-password-salt';

    /**
     * A page request is answered with a redirect to the login page.
     *
     * @throws SPException
     */
    #[Test]
    public function aPageRequestIsSentToTheLoginPage()
    {
        $redirectedTo = $this->whenLoggingOut($this->buildRequest());

        // The underscore marks the parameter as one that is covered by the signature; it is dropped
        // from the query string itself.
        self::assertStringContainsString('r=login', $redirectedTo);
    }

    /**
     * With a route on the request, the login page is told where the user was going, and the URI it
     * is told with is signed.
     *
     * @throws SPException
     */
    #[Test]
    public function theRouteTheUserWasHeadingForIsCarriedAcross()
    {
        $redirectedTo = $this->whenLoggingOut($this->buildRequest('account/view/1', 'a-hash'));

        self::assertStringContainsString('from=account%2Fview%2F1', $redirectedTo);
        self::assertStringContainsString('h=', $redirectedTo, 'the redirect is signed');
    }

    /**
     * The signature on the incoming request is verified before its route is trusted. Without that
     * check, a link with any route on it would come back signed by the application itself.
     *
     * @throws SPException
     */
    #[Test]
    public function aRouteWithABadSignatureIsNotCarriedAcross()
    {
        $request = $this->buildRequest('account/view/1', 'a-hash');
        $request->method('verifySignature')->willThrowException(SPException::error('Invalid signature'));

        self::assertNull(
            $this->whenLoggingOut($request),
            'the redirect never happens, so the route cannot be laundered through the login page'
        );
    }

    /**
     * A route with no signature beside it is not carried across either — the plain login page is
     * what the browser gets.
     *
     * @throws SPException
     */
    #[Test]
    public function anUnsignedRouteIsNotCarriedAcross()
    {
        $redirectedTo = $this->whenLoggingOut($this->buildRequest('account/view/1'));

        self::assertStringContainsString('r=login', $redirectedTo);
        self::assertStringNotContainsString('from=', $redirectedTo);
    }

    /**
     * A background call gets an answer it can act on rather than a redirect it cannot follow: the
     * status the front-end watches for to take the user to the login page itself.
     *
     * @throws SPException
     */
    #[Test]
    public function aJsonCallIsToldTheSessionIsGone()
    {
        $body = null;

        $response = $this->createStub(ResponseService::class);
        $response->method('header')->willReturnSelf();
        $response->method('send')->willReturnSelf();
        $response->method('body')->willReturnCallback(
            static function (string $content) use (&$body, $response) {
                $body = $content;

                return $response;
            }
        );

        $host = $this->buildHost($response);

        $host->logout($this->buildRequest(json: true), $this->buildConfigData(), fn() => null);

        $decoded = json_decode((string)$body);

        self::assertSame('Session not started or timed out', $decoded->description);
        self::assertSame(10, $decoded->status, 'the status the front-end watches for');
    }

    /**
     * @throws SPException
     */
    private function whenLoggingOut(RequestService $request): ?string
    {
        $redirectedTo = null;

        $this->buildHost($this->createStub(ResponseService::class))->logout(
            $request,
            $this->buildConfigData(),
            // Not a static closure: the trait binds it with Closure::call(), the way a controller
            // hands over its own redirect method.
            function (string $uri) use (&$redirectedTo) {
                $redirectedTo = $uri;
            }
        );

        return $redirectedTo;
    }

    private function buildHost(ResponseService $response): ControllerTraitFixture
    {
        // The router is final, so it is built for real around the response under test.
        $router = new Router(new Request(), $response);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        return new ControllerTraitFixture($router, $uriContext);
    }

    private function buildRequest(?string $route = null, ?string $hash = null, bool $json = false): RequestService|Stub
    {
        $request = $this->createStub(RequestService::class);
        $request->method('isJson')->willReturn($json);
        $request->method('isAjax')->willReturn(false);
        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'r' => $route,
                'h' => $hash,
                default => null
            }
        );

        return $request;
    }

    private function buildConfigData(): ConfigDataInterface|Stub
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('getPasswordSalt')->willReturn(self::SALT);

        return $configData;
    }
}

/**
 * The trait is used by controllers and its method is protected; this exposes it, and supplies the
 * two properties the trait expects the using class to hold.
 */
final class ControllerTraitFixture
{
    use ControllerTrait;

    public function __construct(Router $router, private readonly UriContextInterface $uriContext)
    {
        $this->router = $router;
    }

    /**
     * @throws SPException
     */
    public function logout(RequestService $request, ConfigDataInterface $configData, callable $onRedirect): void
    {
        $this->sessionLogout($request, $configData, $onRedirect(...));
    }
}
