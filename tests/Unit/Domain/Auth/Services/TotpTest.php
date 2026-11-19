<?php
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

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Auth\Services;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Auth\Services\Base32;
use SP\Domain\Auth\Services\Totp;

/**
 * RFC 6238 and RFC 4648, against their published test vectors
 */
#[Group('unitary')]
class TotpTest extends TestCase
{
    /**
     * The SHA-1 secret of RFC 6238 Appendix B, "12345678901234567890", in base32.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * RFC 6238 Appendix B, SHA-1. The RFC lists eight-digit codes; a six-digit code is their last
     * six digits, since both are the same truncated value modulo a power of ten.
     *
     * @return array<string, array{int, string}>
     */
    public static function rfc6238Provider(): array
    {
        return [
            '59' => [59, '287082'],
            '1111111109' => [1111111109, '081804'],
            '1111111111' => [1111111111, '050471'],
            '1234567890' => [1234567890, '005924'],
            '2000000000' => [2000000000, '279037'],
            '20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfc6238Provider')]
    public function testTheCodeMatchesRfc6238(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::codeAt(self::RFC_SECRET, Totp::stepAt($time)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rfc4648Provider(): array
    {
        return [
            'empty' => ['', ''],
            'f' => ['f', 'MY'],
            'fo' => ['fo', 'MZXQ'],
            'foo' => ['foo', 'MZXW6'],
            'foob' => ['foob', 'MZXW6YQ'],
            'fooba' => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI'],
            'the RFC 6238 secret' => ['12345678901234567890', self::RFC_SECRET],
        ];
    }

    #[DataProvider('rfc4648Provider')]
    public function testBase32MatchesRfc4648BothWays(string $data, string $encoded): void
    {
        self::assertSame($encoded, Base32::encode($data));
        self::assertSame($data, Base32::decode($encoded));
    }

    public function testBase32ReadsASecretTheWayItIsTyped(): void
    {
        self::assertSame('foobar', Base32::decode('mzxw 6ytb oi======'));
    }

    public function testBase32RefusesACharacterOutsideItsAlphabet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base32::decode('MZXW1');
    }

    public function testANewSecretIs160Bits(): void
    {
        $secret = Totp::generateSecret();

        self::assertSame(20, strlen(Base32::decode($secret)));
        self::assertNotSame($secret, Totp::generateSecret());
    }

    /**
     * The current step and one on either side, for clock drift; nothing further.
     */
    public function testAcceptsTheStepEitherSideAndNoFurther(): void
    {
        $now = 1111111111;
        $step = Totp::stepAt($now);

        foreach ([-1, 0, 1] as $offset) {
            self::assertSame(
                $step + $offset,
                Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step + $offset), $now)
            );
        }

        foreach ([-2, 2] as $offset) {
            self::assertNull(Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step + $offset), $now));
        }
    }

    /**
     * A code already used is refused for the rest of its window, and so is any earlier one.
     */
    public function testACodeAlreadyUsedIsRefused(): void
    {
        $now = 1111111111;
        $step = Totp::stepAt($now);
        $code = Totp::codeAt(self::RFC_SECRET, $step);

        self::assertSame($step, Totp::verify(self::RFC_SECRET, $code, $now));
        self::assertNull(Totp::verify(self::RFC_SECRET, $code, $now, lastAcceptedStep: $step));
        self::assertNull(
            Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step - 1), $now, lastAcceptedStep: $step)
        );
        self::assertSame(
            $step + 1,
            Totp::verify(self::RFC_SECRET, Totp::codeAt(self::RFC_SECRET, $step + 1), $now, lastAcceptedStep: $step)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCodeProvider(): array
    {
        return [
            'empty' => [''],
            'too short' => ['12345'],
            'too long' => ['1234567'],
            'letters' => ['12a456'],
            'signed' => ['-12345'],
        ];
    }

    #[DataProvider('malformedCodeProvider')]
    public function testAMalformedCodeIsRefused(string $code): void
    {
        self::assertNull(Totp::verify(self::RFC_SECRET, $code, 1111111111));
    }

    public function testACodeTypedWithASpaceIsAccepted(): void
    {
        $now = 1111111111;

        self::assertNotNull(Totp::verify(self::RFC_SECRET, '050 471', $now));
    }

    public function testTheProvisioningUriIsWhatAuthenticatorAppsRead(): void
    {
        self::assertSame(
            'otpauth://totp/sysPass:ana%40example.org?secret=' . self::RFC_SECRET
            . '&issuer=sysPass&algorithm=SHA1&digits=6&period=30',
            Totp::provisioningUri(self::RFC_SECRET, 'sysPass', 'ana@example.org')
        );
    }
}
