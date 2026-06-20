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

namespace SP\Tests\Domain\Http\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Core\Crypt\Hash;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Method;
use SP\Domain\Http\Services\Request;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\ServerBag;

use function PHPUnit\Framework\exactly;

/**
 * Class RequestTest
 *
 */
#[Group('unitary')]
class RequestTest extends UnitaryTestCase
{

    private SymfonyRequest|MockObject  $symfonyRequest;
    private CryptPKIHandler|MockObject $cryptPKI;
    private HeaderBag|MockObject       $headers;
    private InputBag                   $paramsGet;
    private ServerBag                  $server;
    private FileBag                    $files;

    /**
     * @throws Exception
     */
    public function testIsJson()
    {
        $this->headers->expects(self::once())
                      ->method('get')
                      ->with('Accept')
                      ->willReturn('application/json');

        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertTrue($request->isJson());
    }

    private function ensureGet(): void
    {
        $this->symfonyRequest
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn('GET');
    }

    /**
     * @throws Exception
     */
    public function testIsJsonFalse()
    {
        $this->headers->expects(self::once())
                      ->method('get')
                      ->with('Accept')
                      ->willReturn('test');

        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertFalse($request->isJson());
    }

    public function testAnalyzeString()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'a_value');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeString('test');

        $this->assertEquals('a_value', $out);
    }

    public function testAnalyzeStringWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeString('test', 'a_default_value');

        $this->assertEquals('a_default_value', $out);
    }

    public function testGetServerPort()
    {
        $this->ensureGet();

        $this->server->set('SERVER_PORT', 1080);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(1080, $request->getServerPort());
    }

    public function testGetServerPortWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(80, $request->getServerPort());
    }

    public function testAnalyzeBool()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'True');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeBool('test');

        $this->assertTrue($out);
    }

    public function testAnalyzeBoolWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeBool('test');

        $this->assertFalse($out);
    }

    public function testGetXForwardedData()
    {
        $host = self::$faker->domainName;
        $proto = 'http';

        $this->ensureGet();

        $this->headers->expects(exactly(4))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['X-Forwarded-Host'],
                                                ['X-Forwarded-Proto'],
                                                ['Forwarded'],
                                                ['X-Forwarded-For'])
                      )
                      ->willReturn($host, $proto, null, null);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $expected = [
            'host' => $host,
            'proto' => $proto,
            'for' => null
        ];

        $this->assertEquals($expected, $request->getXForwardedData());
    }

    public function testGetXForwardedDataWithEmpty()
    {
        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['X-Forwarded-Host'],
                                                ['X-Forwarded-Proto'])
                      )
                      ->willReturn('', '');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertNull($request->getXForwardedData());
    }

    public function testGetFile()
    {
        $this->ensureGet();

        $this->files->set('test_file', ['a' => 'file']);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->getFile('test_file');
        $expected = ['a' => 'file'];

        $this->assertEquals($expected, $out);
    }

    public function testGetFileWithNoEntry()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->getFile('test_file');

        $this->assertNull($out);
    }

    public function testGetMethodWithGet()
    {
        $this->ensureGet();
        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(Method::GET, $request->getMethod());
    }

    public function testGetMethodWithPost()
    {
        $this->ensurePost();
        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(Method::POST, $request->getMethod());
    }

    private function ensurePost(): void
    {
        $this->symfonyRequest
            ->expects(self::once())
            ->method('getMethod')
            ->willReturn('POST');
    }

    public function testGetClientAddressFromServer()
    {
        $address = self::$faker->ipv4;

        $this->server->set('REMOTE_ADDR', $address);

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['Forwarded'], ['X-Forwarded-For'])
                      )
                      ->willReturn(null, null);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $this->assertEquals($address, $request->getClientAddress());
    }

    public function testGetClientAddressWithForwarded()
    {
        $address = self::$faker->ipv4;
        $domain = self::$faker->domainName;

        $this->server->set('REMOTE_ADDR', 'test');

        $this->ensureGet();

        $this->headers->expects(exactly(1))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('for=%s;host=%s;proto=https', $address, $domain));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($address, $request->getClientAddress());
    }

    public function testGetClientAddressWithForwardedFor()
    {
        $address = self::$faker->ipv4;

        $this->server->set('REMOTE_ADDR', 'test');

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['Forwarded'], ['X-Forwarded-For'])
                      )
                      ->willReturn(null, sprintf('%s,', $address));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($address, $request->getClientAddress());
    }

    public function testGetClientAddressWithForwardedForFull()
    {
        $addresses = sprintf('%s,%s', self::$faker->ipv4, self::$faker->ipv6);

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['Forwarded'], ['X-Forwarded-For'])
                      )
                      ->willReturn(null, $addresses);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($addresses, $request->getClientAddress(true));
    }

    public function testGetServer()
    {
        $this->ensureGet();

        $this->server->set('test', 123);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->getServer('test');

        self::assertEquals('123', $out);
    }

    public function testGetServerWithoutEntry()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->getServer('test');

        self::assertEmpty($out);
    }

    public function testAnalyzeArray()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', ['a' => 'test', 'b' => 1]);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeArray('test');

        $this->assertEquals(['a' => 'test', 'b' => 1], $out);
    }

    public function testAnalyzeArrayWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeArray('test', null, ['c' => 'test', 'd' => 1]);

        $this->assertEquals(['c' => 'test', 'd' => 1], $out);
    }

    public function testAnalyzeArrayWithMapper()
    {
        $mapper = static fn(array $items) => array_map('strtoupper', $items);

        $this->ensureGet();

        $this->paramsGet->set('test', ['a' => 'a_test', 'b' => 1]);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeArray('test', $mapper);

        $this->assertEquals(['a' => 'A_TEST', 'b' => 1], $out);
    }

    public function testGetForwardedData()
    {
        $address = self::$faker->ipv4;
        $domain = self::$faker->domainName;

        $this->server->set('REMOTE_ADDR', 'test');

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('for=%s;host=%s;proto=https', $address, $domain));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $expected = [
            'host' => $domain,
            'proto' => 'https',
            'for' => [$address],
        ];

        $this->assertEquals($expected, $request->getForwardedData());
    }

    public function testGetForwardedDataWhitEmptyHost()
    {
        $this->server->set('REMOTE_ADDR', 'test');

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('for=%s;proto=https', self::$faker->ipv4));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertNull($request->getForwardedData());
    }

    public function testGetForwardedDataWhitEmptyProto()
    {
        $this->server->set('REMOTE_ADDR', 'test');

        $this->ensureGet();

        $this->headers->expects(self::exactly(2))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('host=%s;for=192.168.0.1', self::$faker->domainName));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertNull($request->getForwardedData());
    }

    public function testCheckReload()
    {
        $this->ensureGet();

        $this->headers->expects(self::once())
                      ->method('get')
                      ->with('Cache-Control')
                      ->willReturn('max-age=0');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertTrue($request->checkReload());
    }

    public function testCheckReloadFalse()
    {
        $this->ensureGet();

        $this->headers->expects(self::once())
                      ->method('get')
                      ->with('Cache-Control')
                      ->willReturn('test');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertFalse($request->checkReload());
    }

    public function testAnalyzeEmail()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'me@email.com');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeEmail('test');

        $this->assertEquals('me@email.com', $out);
    }

    public function testAnalyzeEmailWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeEmail('test', 'another@email.com');

        $this->assertEquals('another@email.com', $out);
    }

    public function testAnalyzeInt()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 123);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeInt('test');

        $this->assertEquals(123, $out);
    }

    public function testAnalyzeIntWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeInt('test', 456);

        $this->assertEquals(456, $out);
    }

    public function testIsHttps()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertFalse($request->isHttps());
    }

    public function testGetRequest()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($this->symfonyRequest, $request->getRequest());
    }

    public function testIsAjaxWithHeader()
    {
        $this->ensureGet();

        $this->headers
            ->expects(self::once())
            ->method('get')
            ->with('X-Requested-With')
            ->willReturn('XMLHttpRequest');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxWithQueryParameter()
    {
        $this->ensureGet();

        $this->headers
            ->expects(self::once())
            ->method('get')
            ->with('X-Requested-With')
            ->willReturn('test');

        $this->paramsGet->set('isAjax', 1);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxFalse()
    {
        $this->ensureGet();

        $this->headers
            ->expects(self::once())
            ->method('get')
            ->with('X-Requested-With')
            ->willReturn('test');

        $this->paramsGet->set('isAjax', 0);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertFalse($request->isAjax());
    }

    public function testAnalyzeEncrypted()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', base64_encode('value_encrypted'));

        $this->cryptPKI
            ->expects(self::once())
            ->method('decryptRSA')
            ->with('value_encrypted')
            ->willReturn('value');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->analyzeEncrypted('test');

        $this->assertEquals('value', $out);
    }

    public function testAnalyzeEncryptedWithDecryptToNull()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'value_encrypted');

        $this->cryptPKI
            ->expects(self::once())
            ->method('decryptRSA')
            ->with(self::anything())
            ->willReturn(null);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->analyzeEncrypted('test');

        $this->assertEquals('value_encrypted', $out);
    }

    public function testAnalyzeEncryptedWithWrongParam()
    {
        $this->ensureGet();

        $this->cryptPKI->expects(self::never())->method('decryptRSA');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->analyzeEncrypted('test');

        $this->assertEmpty($out);
    }

    public function testAnalyzeEncryptedWithException()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'value_encrypted');

        $this->cryptPKI
            ->expects(self::once())
            ->method('decryptRSA')
            ->willThrowException(new RuntimeException());

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $out = $request->analyzeEncrypted('test');

        $this->assertEquals('value_encrypted', $out);
    }

    /**
     * @throws SPException
     */
    public function testVerifySignature()
    {
        $params = [
            'a' => 1,
            'b' => 2,
            'c' => 3
        ];
        $signature = Hash::signMessage(implode('&', $params), 'a_key');

        $this->ensureGet();

        foreach ($params as $key => $value) {
            $this->paramsGet->set($key, $value);
        }

        $this->paramsGet->set('h', $signature);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $request->verifySignature('a_key');
    }

    /**
     * @throws SPException
     */
    public function testVerifySignatureWithParam()
    {
        $signature = Hash::signMessage('a_value', 'a_key');

        $this->ensureGet();

        $this->paramsGet->set('h', $signature);
        $this->paramsGet->set('test', 'a_value');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $request->verifySignature('a_key', 'test');
    }

    /**
     * @throws SPException
     */
    public function testVerifySignatureWithoutHash()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('URI string altered');

        $request->verifySignature('a_key');
    }

    public function testGetForwardedFor()
    {
        $addresses = [
            self::$faker->ipv4,
            self::$faker->ipv6
        ];

        $this->ensureGet();

        $this->headers->expects(exactly(1))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('for=%s,for=%s', $addresses[0], $addresses[1]));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($addresses, $request->getForwardedFor());
    }

    public function testGetForwardedForWithXForwarded()
    {
        $addresses = [
            self::$faker->ipv4,
            self::$faker->ipv6
        ];

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['Forwarded'], ['X-Forwarded-For'])
                      )
                      ->willReturn(null, sprintf('%s,%s', $addresses[0], $addresses[1]));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals($addresses, $request->getForwardedFor());
    }

    public function testGetForwardedForFail()
    {
        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(['Forwarded'], ['X-Forwarded-For'])
                      )
                      ->willReturn(null, null);

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertNull($request->getForwardedFor());
    }

    public function testAnalyzeUnsafeString()
    {
        $this->ensureGet();

        $this->paramsGet->set('test', 'me@email.com');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeUnsafeString('test');

        $this->assertEquals('me@email.com', $out);
    }

    public function testAnalyzeUnsafeStringWithDefault()
    {
        $this->ensureGet();

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->analyzeUnsafeString('test', 'another@email.com');

        $this->assertEquals('another@email.com', $out);
    }

    public function testGetSecureAppPath()
    {
        $appRoot = preg_replace('#\w+://#', '', REAL_APP_ROOT) . '/public/js';

        $path = '/../../public/js/app.js';

        $out = Request::getSecureAppPath($path, $appRoot);

        $this->assertEquals($appRoot . '/app.js', $out);
    }

    public function testGetSecureAppPathWithUnknownFile()
    {
        $path = '../../opt/project/index.test';

        $out = Request::getSecureAppPath($path);

        $this->assertEmpty($out);
    }

    public function testGetSecureAppPathWhithWrongBase()
    {
        $path = '../../opt/project/index.test';

        $out = Request::getSecureAppPath($path, '/tmp');

        $this->assertEmpty($out);
    }

    public function testGetHttpHost()
    {
        $address = self::$faker->ipv4;
        $domain = self::$faker->domainName;

        $this->ensureGet();

        $this->headers->expects(exactly(2))
                      ->method('get')
                      ->with('Forwarded')
                      ->willReturn(sprintf('for=%s;host=%s;proto=https', $address, $domain));

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(sprintf('https://%s', $domain), $request->getHttpHost());
    }

    public function testGetHttpHostWithXForwarded()
    {
        $domain = self::$faker->domainName;

        $this->ensureGet();

        $this->headers->expects(exactly(4))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(
                              ['Forwarded'],
                              ['X-Forwarded-Host'],
                              ['X-Forwarded-Proto'],
                              ['Forwarded']
                          )
                      )
                      ->willReturn('', $domain, 'https', 'for=10.10.10.10');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        $this->assertEquals(sprintf('https://%s', $domain), $request->getHttpHost());
    }

    public function testGetHttpHostWithXForwardedWithServer()
    {
        $domain = self::$faker->domainName;

        $this->server->set('HTTP_HOST', $domain);

        $this->ensureGet();

        $this->headers->expects(exactly(3))
                      ->method('get')
                      ->with(
                          ...
                          self::withConsecutive(
                              ['Forwarded'],
                              ['X-Forwarded-Host'],
                              ['X-Forwarded-Proto']
                          )
                      )
                      ->willReturn('', '', '');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);

        /** @noinspection HttpUrlsUsage */
        $this->assertEquals(sprintf('http://%s', $domain), $request->getHttpHost());
    }

    public function testGetHeader()
    {
        $this->ensureGet();

        $this->headers
            ->expects(self::once())
            ->method('get')
            ->with('test')
            ->willReturn('a_value');

        $request = new Request($this->symfonyRequest, $this->cryptPKI);
        $out = $request->getHeader('test');

        $this->assertEquals('a_value', $out);
    }

    public function testGetSecureAppFile()
    {
        $appRoot = preg_replace('#\w+://#', '', REAL_APP_ROOT) . '/public/js';

        $path = '/../../public/js/app.js';

        $out = Request::getSecureAppFile($path, $appRoot);

        $this->assertEquals('app.js', $out);
    }

    public function testGetSecureAppFileWithUnknownFile()
    {
        $path = '../../opt/project/index.test';

        $out = Request::getSecureAppFile($path);

        $this->assertEquals('', $out);
    }

    public function testGetSecureAppFileWithWrongBase()
    {
        $path = '../../opt/project/index.php';

        $out = Request::getSecureAppFile($path, '/tmp');

        $this->assertEquals('', $out);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->symfonyRequest = $this->createMock(SymfonyRequest::class);
        $this->cryptPKI = $this->createMock(CryptPKIHandler::class);
        $this->headers = $this->createMock(HeaderBag::class);

        $this->paramsGet = new InputBag();
        $this->server = new ServerBag();
        $this->files = new FileBag();

        // Symfony Request exposes its bags as public properties; wire the mock's
        // bags directly (mirrors the previous router's headers()/paramsGet()/... accessors).
        $this->symfonyRequest->headers = $this->headers;
        $this->symfonyRequest->query = $this->paramsGet;
        $this->symfonyRequest->request = new InputBag();
        $this->symfonyRequest->server = $this->server;
        $this->symfonyRequest->files = $this->files;
    }
}
