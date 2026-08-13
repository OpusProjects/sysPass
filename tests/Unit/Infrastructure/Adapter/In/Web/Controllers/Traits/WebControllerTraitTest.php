<?php
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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Traits;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Traits\WebControllerTrait;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use Symfony\Component\HttpFoundation\Request;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The deep-linking helper every controller shares, and the one place a timed-out session hands the
 * browser back to the login page. Both matter because of what they carry across a redirect: the
 * route the user was headed for, and only when that route's signature actually checks out.
 */
#[Group('unitary')]
class WebControllerTraitTest extends UnitaryTestCase
{
    private const SALT = 'the-password-salt';

    /**
     * A call made before the controller has finished constructing itself is refused outright — the
     * request is never even inspected. This is the guard against the trait being reached from
     * mid-construction code, before $request/$configData are meaningfully wired up.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aCallBeforeSetupIsIgnored(): void
    {
        $request = $this->createMock(RequestService::class);
        $request->expects($this->never())->method('analyzeString');

        $fixture = $this->buildFixture($request, setup: false);

        self::assertNull($fixture->signedUri());
    }

    /**
     * With no route on the request at all, there is nothing to sign or verify — the signature check
     * is never even attempted.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function noRouteMeansNoSignatureCheck(): void
    {
        $request = $this->createMock(RequestService::class);
        $request->expects($this->once())->method('analyzeString')->with('from')->willReturn(null);
        $request->expects($this->never())->method('verifySignature');

        $fixture = $this->buildFixture($request);

        self::assertNull($fixture->signedUri());
    }

    /**
     * A route whose signature checks out is handed back so the caller can deep-link to it.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aValidatedRouteIsReturned(): void
    {
        $request = $this->createStub(RequestService::class);
        $request->method('analyzeString')->willReturn('account/view/1');

        $fixture = $this->buildFixture($request);

        self::assertSame('account/view/1', $fixture->signedUri());
    }

    /**
     * A route with a signature that does not check out is dropped rather than trusted — otherwise
     * anybody could hand a victim a link carrying an arbitrary destination.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function anInvalidSignatureDropsTheRoute(): void
    {
        $request = $this->createStub(RequestService::class);
        $request->method('analyzeString')->willReturn('account/view/1');
        $request->method('verifySignature')->willThrowException(SPException::error('Invalid signature'));

        $fixture = $this->buildFixture($request);

        self::assertNull($fixture->signedUri());
    }

    /**
     * The session-timeout guard delegates straight into the shared logout flow — this just pins
     * that the delegation actually happens, using the background-call path so nothing tries to
     * terminate the test process.
     *
     * @throws Exception
     * @throws SPException
     */
    #[Test]
    public function aTimedOutSessionIsHandedToTheSharedLogoutFlow(): void
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

        $router = new Router(new Request(), $response);

        $request = $this->createStub(RequestService::class);
        $request->method('isJson')->willReturn(true);

        $fixture = $this->buildFixture($request, router: $router);

        $fixture->timeout();

        $decoded = json_decode((string)$body);

        self::assertSame('Session not started or timed out', $decoded->description);
    }

    /**
     * @throws Exception
     */
    private function buildFixture(
        RequestService $request,
        bool           $setup = true,
        ?Router        $router = null
    ): WebControllerTraitFixture {
        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('getPasswordSalt')->willReturn(self::SALT);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        $router ??= new Router(new Request(), $this->createStub(ResponseService::class));

        return new WebControllerTraitFixture($router, $request, $configData, $uriContext, $setup);
    }
}

/**
 * The trait is used by the controller base classes and its members are protected/private; this
 * exposes just enough to drive it, matching the two properties (`$request`, `$configData`) every
 * real user of the trait carries.
 */
final class WebControllerTraitFixture
{
    use WebControllerTrait;

    public function __construct(
        Router                                        $router,
        private readonly RequestService               $request,
        private readonly ConfigDataInterface           $configData,
        private readonly UriContextInterface           $uriContext,
        bool                                           $setup = true
    ) {
        $this->router = $router;
        $this->setup = $setup;
    }

    public function signedUri(): ?string
    {
        return $this->getSignedUriFromRequest($this->request, $this->configData);
    }

    /**
     * @throws SPException
     */
    public function timeout(): void
    {
        $this->handleSessionTimeout();
    }
}
