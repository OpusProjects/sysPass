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

namespace SP\Tests\Unit\Domain\Common\Providers;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use SP\Domain\Common\Providers\Http;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Http\Ports\RequestService;

/**
 * Class HttpUtilTest
 */
#[Group('unitary')]
class HttpTest extends TestCase
{

    /**
     * The address a plaintext request should have been made to.
     *
     * These used to assert only which mock methods had been called, which is why the defect they
     * now pin survived: the method sent a bare `header('Location: …')` with no status code and no
     * exit, so the caller carried on and the response went out over the plaintext connection
     * anyway. A `Location` on a 200 is not a redirect. Asserting the answer, rather than the
     * calls, is the difference.
     *
     * @throws Exception
     */
    public function testHttpsUrlForAPlaintextRequest()
    {
        $configData = $this->createMock(ConfigDataInterface::class);
        $request = $this->createMock(RequestService::class);

        $configData->expects($this->once())->method('isHttpsEnabled')->willReturn(true);
        $request->expects($this->once())->method('isHttps')->willReturn(false);
        $request->expects($this->once())->method('getServerPort')->willReturn(8080);
        $request->expects($this->once())->method('getHttpHost')->willReturn('http://localhost');

        $_SERVER['REQUEST_URI'] = '/index.php?r=account/index';

        self::assertSame(
            'https://localhost:8080/index.php?r=account/index',
            Http::httpsUrlFor($configData, $request)
        );
    }

    /**
     * The standard port is not written out.
     *
     * @throws Exception
     */
    public function testHttpsUrlForOmitsThePortWhenItIsTheDefault()
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $request = $this->createStub(RequestService::class);

        $configData->method('isHttpsEnabled')->willReturn(true);
        $request->method('isHttps')->willReturn(false);
        $request->method('getServerPort')->willReturn(443);
        $request->method('getHttpHost')->willReturn('http://vault.example');

        $_SERVER['REQUEST_URI'] = '/';

        self::assertSame('https://vault.example/', Http::httpsUrlFor($configData, $request));
    }

    /**
     * Only the scheme is rewritten.
     *
     * It was a str_replace of 'http' for 'https' over the whole host, which rewrites every
     * occurrence — so an installation at http://httpbin.example was redirected to
     * https://httpsbin.example, a host that need not exist and need not be theirs.
     *
     * @throws Exception
     */
    public function testHttpsUrlForRewritesOnlyTheScheme()
    {
        $configData = $this->createStub(ConfigDataInterface::class);
        $request = $this->createStub(RequestService::class);

        $configData->method('isHttpsEnabled')->willReturn(true);
        $request->method('isHttps')->willReturn(false);
        $request->method('getServerPort')->willReturn(443);
        $request->method('getHttpHost')->willReturn('http://httpbin.example');

        $_SERVER['REQUEST_URI'] = '/';

        self::assertSame('https://httpbin.example/', Http::httpsUrlFor($configData, $request));
    }

    /**
     * Nothing to do when the setting is off — and the request is not even examined.
     *
     * @throws Exception
     */
    public function testHttpsUrlForIsNullWhenNotEnabled()
    {
        $configData = $this->createMock(ConfigDataInterface::class);
        $request = $this->createMock(RequestService::class);

        $configData->expects($this->once())->method('isHttpsEnabled')->willReturn(false);
        $request->expects($this->never())->method('getServerPort');
        $request->expects($this->never())->method('getHttpHost');

        self::assertNull(Http::httpsUrlFor($configData, $request));
    }

    /**
     * Nor when the request already arrived over HTTPS.
     *
     * @throws Exception
     */
    public function testHttpsUrlForIsNullWhenAlreadyHttps()
    {
        $configData = $this->createMock(ConfigDataInterface::class);
        $request = $this->createMock(RequestService::class);

        $configData->expects($this->once())->method('isHttpsEnabled')->willReturn(true);
        $request->expects($this->once())->method('isHttps')->willReturn(true);
        $request->expects($this->never())->method('getServerPort');
        $request->expects($this->never())->method('getHttpHost');

        self::assertNull(Http::httpsUrlFor($configData, $request));
    }
}
