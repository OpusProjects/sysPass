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

/**
 * Time-based one-time passwords, RFC 6238
 *
 * HMAC-SHA1 over 30-second steps, six digits — what every authenticator app produces by default.
 * Written here rather than taken from a library: it is a few lines, and these are the lines a
 * second factor rests on, so they are kept where they can be read in full.
 *
 * Verification accepts the step before and after the current one, for clock drift between the
 * server and the phone, and is given the last step that was accepted for the user, so that a code
 * already used — seen over a shoulder, or replayed from a captured request — is refused for the
 * rest of its window.
 */
final class Totp
{
    /**
     * The name authenticator apps file sysPass's entries under
     */
    public const ISSUER = 'sysPass';

    public const DIGITS = 6;
    public const PERIOD = 30;

    /**
     * Steps accepted on either side of the current one.
     */
    public const WINDOW = 1;

    /**
     * 160 bits, the length RFC 4226 recommends for an HMAC-SHA1 key.
     */
    private const SECRET_BYTES = 20;

    /**
     * A new secret, base32-encoded for an authenticator app
     */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * The code for the given step
     *
     * @param string $secret Base32
     */
    public static function codeAt(string $secret, int $step): string
    {
        // RFC 4226 §5.3: HMAC over the 8-byte big-endian counter, then dynamic truncation.
        $hash = hash_hmac('sha1', pack('J', $step), Base32::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
                  | (ord($hash[$offset + 1]) << 16)
                  | (ord($hash[$offset + 2]) << 8)
                  | ord($hash[$offset + 3]);

        return str_pad((string)($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step a moment falls in
     */
    public static function stepAt(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The step a code was valid for, or null when it is not a code for this secret right now
     *
     * @param string $secret Base32
     * @param int|null $lastAcceptedStep The step a previous sign-in already used, if any: that
     *                                   step and every earlier one are refused
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastAcceptedStep = null): ?int
    {
        $code = str_replace(' ', '', $code);

        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $current = self::stepAt($time);
        $matched = null;

        // Every step in the window is computed and compared, whatever matches first, so the time
        // a refusal takes does not say how close a guess came.
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (hash_equals(self::codeAt($secret, $step), $code)
                && ($lastAcceptedStep === null || $step > $lastAcceptedStep)
            ) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /**
     * The URI an authenticator app enrols from, usually shown as a QR code
     *
     * @param string $secret Base32
     */
    public static function provisioningUri(string $secret, string $issuer, string $account): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query(
                [
                    'secret' => $secret,
                    'issuer' => $issuer,
                    'algorithm' => 'SHA1',
                    'digits' => self::DIGITS,
                    'period' => self::PERIOD,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
    }
}
