<?php

declare(strict_types=1);
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

namespace SP\Infrastructure\Crypt;

use phpseclib4\Crypt\RSA\PrivateKey;
use Closure;
use phpseclib4\Crypt\RSA;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Domain\Core\Exceptions\FileException;
use Throwable;

use function SP\processException;

/**
 * Class CryptPKI for handling PKI functions
 */
final class CryptPKI implements CryptPKIHandler
{
    public const KEY_SIZE         = 2048;
    public const PUBLIC_KEY_FILE  = 'pubkey.pem';
    public const PRIVATE_KEY_FILE = 'key.pem';

    /**
     * How many times a key pair is generated before giving up
     *
     * phpseclib 4 has the platform's OpenSSL generate the key and then parses what OpenSSL
     * exported, and that parse failed once on CI ("Unable to read key", PHP 8.4) for a key it had
     * just made. It did not reproduce in 400 local generations, so the cause sits in the pairing
     * of a particular OpenSSL build with the parser, for particular keys. Each attempt is a new
     * random key, so trying again is the mitigation; a failure that persists is still reported.
     */
    private const GENERATION_ATTEMPTS = 3;

    /** @var Closure(int): PrivateKey */
    private readonly Closure $keyGenerator;

    /**
     * @throws SPException
     */
    /**
     * @param Closure(int): PrivateKey|null $keyGenerator Generates a key pair of the given size;
     *                                                     phpseclib's unless a test supplies one
     */
    public function __construct(
        private readonly FileHandlerInterface $publicKeyFile,
        private readonly FileHandlerInterface $privateKeyFile,
        ?Closure $keyGenerator = null
    ) {
        $this->keyGenerator = $keyGenerator ?? static fn(int $bits): PrivateKey => RSA::createKey($bits);

        $this->setUp();
    }

    /**
     * Check if private and public keys exist
     *
     * @throws SPException
     */
    private function setUp(): void
    {
        try {
            $this->publicKeyFile->getFileSize(true);
            $this->privateKeyFile->getFileSize(true);
        } catch (FileException $e) {
            processException($e);

            $this->createKeys();
        }
    }

    /**
     * Generates a key pair, trying again when the library fails on the one it just made
     *
     * @throws Throwable The last attempt's failure, when every attempt fails
     */
    private function generateKey(): PrivateKey
    {
        $attempt = 0;

        while (true) {
            try {
                return ($this->keyGenerator)(self::KEY_SIZE);
            } catch (Throwable $e) {
                if (++$attempt >= self::GENERATION_ATTEMPTS) {
                    throw $e;
                }

                processException($e);
            }
        }
    }

    /**
     * Creates the public and private key pair
     *
     * @throws FileException
     */
    public function createKeys(): void
    {
        $privateKey = $this->generateKey();

        $this->publicKeyFile->save((string)$privateKey->getPublicKey());
        $this->privateKeyFile->save((string)$privateKey)->chmod(0600);
    }

    public static function getMaxDataSize(): int
    {
        return (self::KEY_SIZE / 8) - 11;
    }

    /**
     * Returns the public key from the file
     *
     * @throws FileException
     */
    public function getPublicKey(): string
    {
        return $this->publicKeyFile->checkFileExists()->readToString();
    }

    /**
     * Decrypt data encrypted with the public key
     *
     * @throws FileException
     */
    public function decryptRSA(string $data): ?string
    {
        $privateKeyPem = $this->getPrivateKey();

        // A valid RSA ciphertext is always exactly KEY_SIZE/8 bytes; anything else is
        // invalid input — phpseclib returns binary garbage instead of throwing in that case.
        if (strlen($data) !== self::KEY_SIZE / 8) {
            return null;
        }

        try {
            $privateKey = RSA::loadPrivateKey($privateKeyPem);

            // loadPrivateKey() is declared to return the generic Common\PrivateKey
            // interface, but RSA::loadPrivateKey() always yields an RSA\PrivateKey in
            // practice; withPadding() is RSA-specific (not applicable to DSA/EC keys),
            // hence not part of the generic interface.
            if (!$privateKey instanceof RSA\PrivateKey) {
                return null;
            }

            // The browser encrypts with PKCS#1 v1.5 padding (JSEncrypt).
            return $privateKey->withPadding(RSA::ENCRYPTION_PKCS1)->decrypt($data) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @throws FileException
     */
    private function getPrivateKey(): string
    {
        return $this->privateKeyFile->checkFileExists()->readToString();
    }
}
