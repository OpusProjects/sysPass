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

namespace SP\Domain\Auth\Services;

/**
 * Single-use recovery codes for a second factor
 *
 * Twelve base32 characters, shown as two groups of six: 60 bits each, which no rate of guessing
 * through a sign-in limiter approaches. That is also why a keyed SHA-256 is enough to store them,
 * where a password needs a slow hash: there is nothing short to search. A keyed hash rather than
 * a salted one per code, so a sign-in can find the code by its hash in one statement and spend it
 * in the same one.
 */
final class RecoveryCode
{
    public const COUNT = 10;

    private const BYTES = 8;
    private const LENGTH = 12;

    /**
     * A new code, formatted as it is shown to the user
     */
    public static function generate(): string
    {
        $code = substr(Base32::encode(random_bytes(self::BYTES)), 0, self::LENGTH);

        return strtolower(substr($code, 0, 6) . '-' . substr($code, 6));
    }

    /**
     * The code as typed, reduced to what is compared: case, spaces and dashes do not count
     *
     * @return string|null Null when what was typed cannot be a recovery code
     */
    public static function normalize(string $typed): ?string
    {
        $code = strtoupper(str_replace([' ', '-'], '', $typed));

        if (strlen($code) !== self::LENGTH || preg_match('/^[A-Z2-7]+$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    /**
     * What is stored for a normalized code
     */
    public static function hash(string $normalized, string $key): string
    {
        return hash_hmac('sha256', $normalized, $key);
    }
}
