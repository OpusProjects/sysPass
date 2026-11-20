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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Auth\Services\RecoveryCode;

/**
 * Recovery codes: their format, how a typed one is read, and how it is stored
 */
#[Group('unitary')]
class RecoveryCodeTest extends TestCase
{
    public function testACodeIsTwoGroupsOfSixAndReadsBackAsItself(): void
    {
        $code = RecoveryCode::generate();

        self::assertMatchesRegularExpression('/^[a-z2-7]{6}-[a-z2-7]{6}$/', $code);
        self::assertSame(strtoupper(str_replace('-', '', $code)), RecoveryCode::normalize($code));
    }

    public function testCodesDoNotRepeat(): void
    {
        $codes = array_map(static fn() => RecoveryCode::generate(), range(1, 200));

        self::assertCount(200, array_unique($codes));
    }

    /**
     * Copied from a sheet or typed by hand: case, spaces and the dash do not matter.
     */
    public function testATypedCodeIsReadWhicheverWayItWasTyped(): void
    {
        self::assertSame('ABCDEFGHIJ23', RecoveryCode::normalize('abcdef-ghij23'));
        self::assertSame('ABCDEFGHIJ23', RecoveryCode::normalize(' ABCDEF GHIJ23 '));
        self::assertSame('ABCDEFGHIJ23', RecoveryCode::normalize('abcdefghij23'));
    }

    /**
     * A six-digit one-time code is not a recovery code, which is how sign-in tells them apart.
     *
     * @return array<string, array{string}>
     */
    public static function notARecoveryCodeProvider(): array
    {
        return [
            'a one-time code' => ['123456'],
            'too short' => ['abcdef-ghij2'],
            'too long' => ['abcdef-ghij234'],
            'outside the alphabet' => ['abcdef-ghij18'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notARecoveryCodeProvider')]
    public function testWhatCannotBeACodeIsNotReadAsOne(string $typed): void
    {
        self::assertNull(RecoveryCode::normalize($typed));
    }

    /**
     * Keyed: the same code under another installation's key is a different value, so a table of
     * hashes is no use without config.xml.
     */
    public function testTheStoredHashDependsOnTheKey(): void
    {
        self::assertSame(RecoveryCode::hash('ABCDEFGHIJ23', 'k1'), RecoveryCode::hash('ABCDEFGHIJ23', 'k1'));
        self::assertNotSame(RecoveryCode::hash('ABCDEFGHIJ23', 'k1'), RecoveryCode::hash('ABCDEFGHIJ23', 'k2'));
        self::assertStringNotContainsString('ABCDEFGHIJ23', RecoveryCode::hash('ABCDEFGHIJ23', 'k1'));
    }
}
