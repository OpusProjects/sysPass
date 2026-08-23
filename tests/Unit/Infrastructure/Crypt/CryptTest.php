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

use Defuse\Crypto\Key;
use Defuse\Crypto\KeyProtectedByPassword;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use SP\Infrastructure\Crypt\Crypt;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class CryptTest
 */
#[Group('unitary')]
class CryptTest extends UnitaryTestCase
{
    /**
     * Comprobar la generación de una llave de cifrado
     *
     * @throws CryptException
     */
    public function testMakeSecuredKey()
    {
        $key = (new Crypt())->makeSecuredKey(self::$faker->password());

        $this->assertIsString($key, 'the key is stored as text, so it has to be text');
        $this->assertNotEmpty($key);
    }

    /**
     * Comprobar la generación de una llave de cifrado
     *
     * @throws CryptException
     */
    public function testMakeSecuredKeyNoAscii()
    {
        $this->assertInstanceOf(
            KeyProtectedByPassword::class,
            (new Crypt())->makeSecuredKey(self::$faker->password(), false)
        );
    }

    /**
     * Comprobar la encriptación y desencriptado de datos
     *
     * @throws CryptException
     */
    public function testEncryptAndDecrypt()
    {
        $crypt = new Crypt();

        $password = self::$faker->password();

        $key = $crypt->makeSecuredKey($password);

        $data = self::$faker->text();

        $out = $crypt->encrypt($data, $key, $password);

        $this->assertSame($data, $crypt->decrypt($out, $key, $password));
    }

    /**
     * Comprobar la encriptación y desencriptado de datos
     *
     * @throws CryptException
     */
    public function testEncryptAndDecryptWithDifferentPassword()
    {
        $crypt = new Crypt();

        $password = self::$faker->password();

        $key = $crypt->makeSecuredKey($password);

        $data = $crypt->encrypt('prueba', $key, $password);

        $this->expectException(CryptException::class);

        $crypt->decrypt($data, $key, 'test');
    }

    /**
     * A key that was never protected by a password is used as it is. This is the form the session
     * key takes, so every account password shown in a session goes through it.
     *
     * @throws CryptException
     */
    #[Test]
    public function aPlainKeyRoundTripsWithoutAPassword()
    {
        $crypt = new Crypt();
        $key = Key::createNewRandomKey();

        self::assertSame('the secret', $crypt->decrypt($crypt->encrypt('the secret', $key), $key));
    }

    /**
     * The same key stored as text — how it is held between requests.
     *
     * @throws CryptException
     */
    #[Test]
    public function aPlainKeyRoundTripsInItsStoredForm()
    {
        $crypt = new Crypt();
        $key = Key::createNewRandomKey()->saveToAsciiSafeString();

        self::assertSame('the secret', $crypt->decrypt($crypt->encrypt('the secret', $key), $key));
    }

    /**
     * And a password-protected key handed over as the object rather than as text, which is what
     * makeSecuredKey() returns when it is not asked for the ascii form.
     *
     * @throws CryptException
     */
    #[Test]
    public function aPasswordProtectedKeyObjectDecrypts()
    {
        $crypt = new Crypt();
        $password = 'the-key-password';

        /** @var KeyProtectedByPassword $key */
        $key = $crypt->makeSecuredKey($password, false);

        $encrypted = $crypt->encrypt('the secret', $key->unlockKey($password));

        self::assertSame('the secret', $crypt->decrypt($encrypted, $key, $password));
    }

    /**
     * Ciphertext that has been altered is refused rather than decrypted into something else. This
     * is the check that makes a stored password tamper-evident: the row cannot be edited in the
     * database to change what the account's password decrypts to.
     *
     * @throws CryptException
     */
    #[Test]
    public function alteredCiphertextIsRefused()
    {
        $crypt = new Crypt();
        $key = Key::createNewRandomKey()->saveToAsciiSafeString();

        $encrypted = $crypt->encrypt('the secret', $key);

        $this->expectException(CryptException::class);

        $crypt->decrypt(substr($encrypted, 0, -2) . 'ff', $key);
    }

    /**
     * A key that is not a key at all is refused rather than being used as one.
     *
     * @throws CryptException
     */
    #[Test]
    public function somethingThatIsNotAKeyIsRefused()
    {
        $this->expectException(CryptException::class);

        (new Crypt())->encrypt('the secret', 'not-a-key');
    }

    /**
     * Encrypting with the wrong password for the key fails at the unlock, so nothing is encrypted
     * under a key the caller did not actually hold.
     *
     * @throws CryptException
     */
    #[Test]
    public function encryptingWithTheWrongKeyPasswordIsRefused()
    {
        $crypt = new Crypt();
        $key = $crypt->makeSecuredKey('the-key-password');

        $this->expectException(CryptException::class);

        $crypt->encrypt('the secret', $key, 'not-the-key-password');
    }

    /**
     * unlockSecuredKey() is private, and both of its callers (encrypt() and decrypt()) always pass
     * $useAscii = false — so its `if ($useAscii)` branch, which re-encodes the unlocked inner key
     * back into an ascii-safe string rather than returning the Key object, is never reached through
     * the class's public surface today. It is still real, correctly-implemented behaviour rather
     * than a copy-paste leftover: reached directly through reflection, it must hand back text that
     * is itself a usable key, not merely a string.
     *
     * @throws CryptException
     */
    #[Test]
    public function unlockingAPasswordProtectedKeyInAsciiFormReturnsUsableKeyText(): void
    {
        $crypt = new Crypt();
        $password = 'the-key-password';

        $protectedKey = $crypt->makeSecuredKey($password);

        $unlockSecuredKey = new ReflectionMethod(Crypt::class, 'unlockSecuredKey');
        $unlockedKeyText = $unlockSecuredKey->invoke($crypt, $protectedKey, $password, true);

        $this->assertIsString($unlockedKeyText);
        self::assertSame(
            'the secret',
            $crypt->decrypt($crypt->encrypt('the secret', $unlockedKeyText), $unlockedKeyText)
        );
    }
}
