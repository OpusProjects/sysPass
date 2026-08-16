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

use Faker\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Crypt\Hash;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class HashTest
 *
 */
#[Group('unitary')]
class HashTest extends UnitaryTestCase
{
    public function testHashKey()
    {
        for ($i = 2; $i <= 128; $i *= 2) {
            $key = self::$faker->password(2, $i);
            $hash = Hash::hashKey($key);

            $this->assertNotEmpty($hash);
            $this->assertTrue(Hash::checkHashKey($key, $hash));
        }
    }

    /**
     * bcrypt stops at 72 **bytes** and says nothing about it, which is what the length check here
     * exists to prevent — and it measured characters.
     *
     * Forty CJK characters are forty by that measure and a hundred and twenty bytes, so the check
     * passed them straight through and bcrypt cut them at the seventy-second byte. Everything the
     * owner typed after it counted for nothing, and any other password sharing that prefix opened
     * the same account. Latin-script passwords were never affected, which is why it went unnoticed.
     *
     * Hash::hashKey() is what stores a user's login password (UserPass), the master password hash
     * and the API token hashes.
     */
    public function testAMultibytePasswordIsNotCutAtSeventyTwoBytes(): void
    {
        $password = str_repeat('パ', 40);

        self::assertSame(40, mb_strlen($password));
        self::assertSame(120, strlen($password));

        $hash = Hash::hashKey($password);

        self::assertTrue(Hash::checkHashKey($password, $hash));

        // The two that used to be accepted.
        self::assertFalse(Hash::checkHashKey(substr($password, 0, 72), $hash));
        self::assertFalse(Hash::checkHashKey(substr($password, 0, 72) . 'a different tail', $hash));
    }

    /**
     * A hash written while the limit was measured in characters still has to verify, or upgrading
     * would lock out exactly the people the fix is for — and they need to be able to sign in to
     * change the password that was being truncated.
     */
    public function testAHashWrittenBeforeTheFixStillVerifies(): void
    {
        $password = str_repeat('パ', 40);

        // Exactly what the old code wrote: no pre-hash, because it counted 40 characters.
        $legacyHash = password_hash($password, PASSWORD_BCRYPT);

        self::assertTrue(Hash::checkHashKey($password, $legacyHash));
    }

    /**
     * The fallback accepts only what the old code accepted, and nothing else: a password that is
     * long in both measures is pre-hashed both ways, so there is no second chance to be had.
     */
    public function testTheFallbackDoesNotAcceptAnythingNew(): void
    {
        $password = str_repeat('パ', 40);
        $hash = Hash::hashKey($password);

        self::assertFalse(Hash::checkHashKey(str_repeat('パ', 39), $hash));
        self::assertFalse(Hash::checkHashKey(str_repeat('パ', 41), $hash));
        self::assertFalse(Hash::checkHashKey('', $hash));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function passwordProvider(): array
    {
        return [
            'short ascii' => ['hunter2'],
            'exactly the limit' => [str_repeat('a', 72)],
            'one byte over' => [str_repeat('a', 73)],
            'long ascii' => [str_repeat('a', 400)],
            'multibyte under the limit' => [str_repeat('é', 30)],
            'multibyte over the limit in bytes only' => [str_repeat('パ', 40)],
            'multibyte over in both' => [str_repeat('パ', 100)],
        ];
    }

    #[DataProvider('passwordProvider')]
    public function testAPasswordVerifiesAgainstItsOwnHash(string $password): void
    {
        self::assertTrue(Hash::checkHashKey($password, Hash::hashKey($password)));
        self::assertFalse(Hash::checkHashKey($password . 'x', Hash::hashKey($password)));
    }

    public function testSignMessage()
    {
        $faker = Factory::create();

        for ($i = 2; $i <= 128; $i *= 2) {
            $text = $faker->text();

            $key = self::$faker->password(2, $i);
            $hash = Hash::signMessage($text, $key);

            $this->assertNotEmpty($hash);
            $this->assertTrue(Hash::checkMessage($text, $key, $hash));
        }
    }
}
