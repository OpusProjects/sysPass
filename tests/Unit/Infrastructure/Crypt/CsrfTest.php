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
        return [[Method::POST], [Method::GET]];
    }

    /**
     * Actions a plain GET could reach without the token, before the token was asked of every
     * request: each changes something.
     *
     * @return array<string, array{string}>
     */
    public static function stateChangingRouteProvider(): array
    {
        return [
            'clear the event log' => ['eventlog/clear'],
            'delete a category' => ['category/delete/5'],
            'save the general settings' => ['configGeneral/save'],
            'mark a favourite' => ['accountFavorite/mark/5'],
            'clear the lockouts' => ['track/clear'],
        ];
    }

    /**
     * What a browser opens without the token: the page, links from an email or a public link,
     * and downloads.
     *
     * @return array<string, array{string}>
     */
    public static function plainNavigationProvider(): array
    {
        return [
            'the page, no route' => [''],
            'the page' => ['index'],
            'the page, explicit' => ['index/index'],
            'the login page' => ['login'],
            'logout' => ['login/logout'],
            'password reset' => ['userPassReset'],
            'reset link from an email' => ['userPassReset/reset/abc123'],
            'a public link' => ['account/viewLink/abc123'],
            'a file download' => ['accountFile/download/5'],
            'a PDF preview' => ['accountFile/view/5'],
            'an application backup' => ['configBackup/downloadBackupApp'],
            'a database backup' => ['configBackup/downloadBackupDb'],
            'an export' => ['configBackup/downloadExport'],
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
     * A signed-in request carrying the session's token passes, whatever its method.
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckWithValidToken(Method $method)
    {
        $sessionToken = bin2hex(random_bytes(32));

        $this->signedInWith($sessionToken);
        $this->requestInterface->method('getMethod')->willReturn($method);
        $this->requestInterface->method('getHeader')->willReturnCallback(static fn(string $header): string => $header === 'X-CSRF' ? $sessionToken : '');

        self::assertTrue($this->csrf->check());
    }

    /**
     * A token that does not match the one held in the session is rejected, and the User-Agent
     * and client address are never consulted: the client address comes from the caller-supplied
     * Forwarded header, so binding the token to it both weakened it and broke sessions whenever
     * that value changed. A wrong token is not rescued by the route being a navigation.
     */
    #[DataProvider('httpMethodDataProvider')]
    public function testCheckIsBoundToTheSessionTokenOnly(Method $method)
    {
        $this->signedInWith(bin2hex(random_bytes(32)));
        $this->requestInterface->method('getMethod')->willReturn($method);
        $this->requestInterface->method('getHeader')->willReturnCallback(static fn(string $header): string => $header === 'X-CSRF' ? bin2hex(random_bytes(32)) : '');
        $this->requestInterface->method('analyzeString')->willReturn('index');
        $this->requestInterface->expects(self::never())->method('getClientAddress');

        self::assertFalse($this->csrf->check());
    }

    public function testCheckWithNoTokenOnAPost()
    {
        $this->signedInWith(self::$faker->sha1());
        $this->requestInterface->method('getMethod')->willReturn(Method::POST);
        $this->requestInterface->method('getHeader')->willReturn('');
        $this->requestInterface->method('analyzeString')->willReturn('index');

        self::assertFalse($this->csrf->check());
    }

    /**
     * The defect: a plain GET without the token used to pass, so a link or an image reached any
     * action with the session cookie alone.
     */
    #[DataProvider('stateChangingRouteProvider')]
    public function testAPlainGetWithoutTheTokenCannotChangeAnything(string $route)
    {
        $this->signedInWith(self::$faker->sha1());
        $this->requestInterface->method('getMethod')->willReturn(Method::GET);
        $this->requestInterface->method('getHeader')->willReturn('');
        $this->requestInterface->method('analyzeString')->willReturnCallback(static fn(string $param): ?string => $param === 'r' ? $route : null);

        self::assertFalse($this->csrf->check());
    }

    /**
     * And what a browser opens directly still opens.
     */
    #[DataProvider('plainNavigationProvider')]
    public function testAPlainGetWithoutTheTokenStillNavigates(string $route)
    {
        $this->signedInWith(self::$faker->sha1());
        $this->requestInterface->method('getMethod')->willReturn(Method::GET);
        $this->requestInterface->method('getHeader')->willReturn('');
        $this->requestInterface->method('analyzeString')->willReturnCallback(static fn(string $param): ?string => $param === 'r' ? $route : null);

        self::assertTrue($this->csrf->check());
    }

    public function testCheckWithNoLogin()
    {
        $this->sessionContext->method('isLoggedIn')->willReturn(false);
        $this->requestInterface->expects(self::never())->method('getHeader');

        self::assertTrue($this->csrf->check());
    }

    /**
     * Before the session has a token there is nothing to compare against.
     */
    public function testCheckWithNoCsrf()
    {
        $this->sessionContext->method('isLoggedIn')->willReturn(true);
        $this->sessionContext->method('getCSRF')->willReturn(null);

        self::assertTrue($this->csrf->check());
    }

    private function signedInWith(string $sessionToken): void
    {
        $this->sessionContext->method('isLoggedIn')->willReturn(true);
        $this->sessionContext->method('getCSRF')->willReturn($sessionToken);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionContext = $this->createMock(SessionContext::class);
        $this->requestInterface = $this->createMock(RequestService::class);

        $this->csrf = new Csrf($this->sessionContext, $this->requestInterface);
    }
}
