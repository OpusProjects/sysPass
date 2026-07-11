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

namespace SP\Tests\Unit\Infrastructure\Crypt;

use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Infrastructure\Crypt\CryptPKI;
use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Infrastructure\File\FileException;
use SP\Tests\Support\UnitaryTestCase;

use function PHPUnit\Framework\once;

/**
 * Class CryptPKITest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CryptPKITest extends UnitaryTestCase
{
    private CryptPKI                        $cryptPki;
    private FileHandlerInterface|MockObject $privateKey;
    private FileHandlerInterface|MockObject $publicKey;

    /**
     * @throws FileException
     */
    public function testDecryptRSA()
    {
        // Round-trip with a real key pair: encrypt with the public key the way the
        // browser (JSEncrypt, PKCS#1 v1.5) would, then decrypt through CryptPKI.
        $keyPair = RSA::createKey(CryptPKI::KEY_SIZE);
        $encrypted = $keyPair->getPublicKey()
                             ->withPadding(RSA::ENCRYPTION_PKCS1)
                             ->encrypt('test');

        $this->privateKey->expects(once())->method('checkFileExists')->willReturnSelf();
        $this->privateKey->expects(once())->method('readToString')->willReturn((string)$keyPair);

        $this->assertSame('test', $this->cryptPki->decryptRSA($encrypted));
    }

    /**
     * @throws FileException
     */
    public function testDecryptRSAWithInvalidDataReturnsNull()
    {
        $keyPair = RSA::createKey(CryptPKI::KEY_SIZE);

        $this->privateKey->expects(once())->method('checkFileExists')->willReturnSelf();
        $this->privateKey->expects(once())->method('readToString')->willReturn((string)$keyPair);

        $this->assertNull($this->cryptPki->decryptRSA('not a valid ciphertext'));
    }

    /**
     * @throws FileException
     */
    public function testGetPublicKey()
    {
        $this->publicKey->expects(once())->method('checkFileExists')->willReturnSelf();
        $this->publicKey->expects(once())->method('readToString')->willReturn('test');

        $this->assertEquals('test', $this->cryptPki->getPublicKey());
    }

    /**
     * @throws Exception
     */
    public function testCreateKeys()
    {
        // Fresh handlers so the constructor's existence check triggers createKeys().
        $publicKey = $this->createMock(FileHandlerInterface::class);
        $privateKey = $this->createMock(FileHandlerInterface::class);

        $publicKey->method('getFileSize')->willReturn(0);
        $privateKey->method('getFileSize')->willThrowException(new FileException('test'));

        $publicKey->expects(once())->method('save')->with($this->stringContains('PUBLIC KEY'));
        $privateKey->expects(once())->method('save')->with($this->stringContains('PRIVATE KEY'))->willReturnSelf();
        $privateKey->expects(once())->method('chmod')->with(0600);

        new CryptPKI($publicKey, $privateKey);
    }

    public function testGetMaxDataSize()
    {
        $this->assertEquals(245, CryptPKI::getMaxDataSize());
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->privateKey = $this->createMock(FileHandlerInterface::class);
        $this->publicKey = $this->createMock(FileHandlerInterface::class);

        $this->cryptPki = new CryptPKI($this->publicKey, $this->privateKey);
    }
}
