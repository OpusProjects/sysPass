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
            ->expects(self::once())
            ->method('isLoggedIn')
            ->willReturn(true);

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

        $this->sessionContext->method('isLoggedIn')->willReturn(true);
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
            ->method('isLoggedIn')
            ->willReturn(true);

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
            ->method('isLoggedIn')
            ->willReturn(true);

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
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn(self::$faker->sha1());

        self::assertFalse($this->csrf->check());
    }

    /**
     * @return void
     */
    public function testCheckWithNoLogin()
    {
        $this->requestInterface
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn(Method::GET);

        $this->requestInterface
            ->expects(self::once())
            ->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('test');

        $this->sessionContext
            ->expects(self::once())
            ->method('isLoggedIn')
            ->willReturn(false);

        self::assertTrue($this->csrf->check());
    }

    /**
     * @return void
     */
    public function testCheckWithNoCsrf()
    {
        $this->requestInterface
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn(Method::GET);

        $this->requestInterface
            ->expects(self::once())
            ->method('getHeader')
            ->with('X-Requested-With')
            ->willReturn('test');

        $this->sessionContext
            ->expects(self::once())
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->sessionContext
            ->expects(self::once())
            ->method('getCSRF')
            ->willReturn(null);

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
