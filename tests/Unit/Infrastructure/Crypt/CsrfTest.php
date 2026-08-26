<?php
declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Infrastructure\Crypt;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Infrastructure\Crypt\Csrf;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Http\Method;
use SP\Domain\Http\Ports\RequestService;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class CsrfTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CsrfTest extends UnitaryTestCase
{

    private SessionContext|MockObject $sessionContext;
    private RequestService|MockObject $requestInterface;
    private Csrf                      $csrf;

    public static function httpMethodDataProvider(): array
    {
        return [
            [Method::POST, 'test'],
            [Method::GET, 'XMLHttpRequest']
        ];
    }

    public function testInitialize()
    {
        $this->sessionContext
            ->expects(self::never())
            ->method('isLoggedIn');

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn(null);

        $token = null;

        $this->sessionContext
            ->expects(self::once())
            ->method('setCSRF')
            ->with(
                self::callback(static function (string $value) use (&$token): bool {
                    $token = $value;

                    return true;
                })
            );

        $this->csrf->initialize();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$token);
    }

    /**
     * The token must carry its own entropy. Deriving it from request data gave every client
     * sharing a User-Agent and address the same token.
     */
    public function testInitializeIssuesADifferentTokenEachTime()
    {
        $tokens = [];

        $this->sessionContext->method('getCSRF')->willReturn(null);
        $this->sessionContext
            ->expects(self::exactly(2))
            ->method('setCSRF')
            ->with(
                self::callback(static function (string $value) use (&$tokens): bool {
                    $tokens[] = $value;

                    return true;
                })
            );

        $this->csrf->initialize();
        $this->csrf->initialize();

        self::assertCount(2, $tokens);
        self::assertNotSame($tokens[0], $tokens[1]);
    }

    /**
     * @return void
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckWithValidToken(Method $method, string $header)
    {
        $sessionToken = bin2hex(random_bytes(32));

        $this->requestInterface
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn($method);

        $this->requestInterface
            ->expects(self::exactly(2))
            ->method('getHeader')
            ->with(...self::withConsecutive(['X-Requested-With'], ['X-CSRF']))
            ->willReturn($header, $sessionToken);

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn($sessionToken);

        self::assertTrue($this->csrf->check());
    }

    /**
     * A token that does not match the one held in the session is rejected, and the User-Agent
     * and client address are never consulted: the client address comes from the caller-supplied
     * Forwarded header, so binding the token to it both weakened it and broke sessions whenever
     * that value changed.
     *
     * @return void
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckIsBoundToTheSessionTokenOnly(Method $method, string $header)
    {
        $this->requestInterface
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn($method);

        $this->requestInterface
            ->expects(self::exactly(2))
            ->method('getHeader')
            ->with(...self::withConsecutive(['X-Requested-With'], ['X-CSRF']))
            ->willReturn($header, bin2hex(random_bytes(32)));

        $this->requestInterface
            ->expects(self::never())
            ->method('getClientAddress');

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn(bin2hex(random_bytes(32)));

        self::assertFalse($this->csrf->check());
    }

    /**
     * @return void
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckWithNoToken(Method $method, string $header)
    {
        $this->requestInterface
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn($method);

        $this->requestInterface
            ->expects(self::exactly(2))
            ->method('getHeader')
            ->with(...self::withConsecutive(['X-Requested-With'], ['X-CSRF']))
            ->willReturn($header, '');

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn(self::$faker->sha1());

        self::assertFalse($this->csrf->check());
    }

    /**
     * A request that changes nothing is not checked.
     *
     * A plain GET without the ajax header is a page being read, and the token cannot travel on one
     * — there is no header to put it in.
     */
    public function testCheckLetsAPlainReadThrough()
    {
        $this->requestInterface->expects(self::once())->method('getMethod')->willReturn(Method::GET);
        $this->requestInterface
            ->expects(self::once())
            ->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('');

        $this->sessionContext->expects(self::never())->method('getCSRF');

        self::assertTrue($this->csrf->check());
    }

    /**
     * A session with no token fails a state-changing request rather than passing it.
     *
     * This is the case that used to be waved through, and with it every request made before
     * signing in — the sign-in itself above all, which is login CSRF: a cross-site form posting a
     * username and password signs the victim's browser into the attacker's account, and they go on
     * filing passwords into a vault the attacker can open. A browser that has never loaded a page
     * of ours holds no token, which is exactly the request to refuse.
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckRefusesAStateChangingRequestWithNoSessionToken(Method $method, string $header)
    {
        $this->requestInterface->expects(self::once())->method('getMethod')->willReturn($method);
        $this->requestInterface
            ->expects(self::once())
            ->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn($header);

        $this->sessionContext->expects(self::once())->method('getCSRF')->willReturn(null);

        self::assertFalse($this->csrf->check());
    }

    /**
     * Being signed in is not what decides it, either way. The token is the whole check.
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckDoesNotAskWhetherTheSessionIsSignedIn(Method $method, string $header)
    {
        $token = str_repeat('a', 64);

        $this->requestInterface->method('getMethod')->willReturn($method);
        $this->requestInterface
            ->method('getHeader')
            ->willReturnMap([['X-Requested-With', $header], ['X-CSRF', $token]]);

        $this->sessionContext->expects(self::never())->method('isLoggedIn');
        $this->sessionContext->method('getCSRF')->willReturn($token);

        self::assertTrue($this->csrf->check());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionContext = $this->createMock(SessionContext::class);
        $this->requestInterface = $this->createMock(RequestService::class);

        $this->csrf = new Csrf($this->sessionContext, $this->requestInterface);
    }
}
