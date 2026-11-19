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

namespace SP\Domain\Auth\Services;

use InvalidArgumentException;

/**
 * RFC 4648 base32, the encoding authenticator apps expect a TOTP secret in
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        // Unpadded: the form an otpauth:// secret takes.
        return $data === '' ? '' : $encoded;
    }

    /**
     * Accepts the usual ways a secret is shown or typed: any case, spaces, and trailing padding.
     *
     * @throws InvalidArgumentException For a character outside the alphabet
     */
    public static function decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(str_replace(' ', '', $encoded), '='));
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $value = strpos(self::ALPHABET, $char);

            if ($value === false) {
                throw new InvalidArgumentException('Not a base32 string');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        // Whole bytes only; the remaining bits are padding.
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $encoded === '' ? '' : $decoded;
    }
}
