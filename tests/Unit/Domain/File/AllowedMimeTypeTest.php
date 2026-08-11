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

namespace SP\Tests\Unit\Domain\File;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\File\AllowedMimeType;

/**
 * Class AllowedMimeTypeTest
 */
#[Group('unitary')]
class AllowedMimeTypeTest extends TestCase
{
    private const ALLOWED = ['application/pdf', 'image/png', 'application/x-pkcs12'];

    public function testDetectedTypeOnTheListIsUsed(): void
    {
        self::assertSame(
            'application/pdf',
            AllowedMimeType::resolve('application/pdf', 'image/png', self::ALLOWED)
        );
    }

    /**
     * The detected type wins even when the caller declares a different allowed one, so the
     * upload is stored as what it actually is.
     */
    public function testDetectedTypeWinsOverTheDeclaredOne(): void
    {
        self::assertSame(
            'image/png',
            AllowedMimeType::resolve('image/png', 'application/pdf', self::ALLOWED)
        );
    }

    /**
     * The bypass this closes: content the server positively identified as something outside the
     * allow-list must not become storable just because the caller declares a permitted type.
     */
    #[DataProvider('identifiedButDisallowedProvider')]
    public function testDeclaredTypeCannotLaunderIdentifiedContent(string $detected): void
    {
        self::assertNull(AllowedMimeType::resolve($detected, 'application/pdf', self::ALLOWED));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function identifiedButDisallowedProvider(): array
    {
        return [
            ['text/x-php'],
            ['text/html'],
            ['application/x-executable'],
            ['application/zip'],
            ['image/svg+xml'],
        ];
    }

    /**
     * When the server cannot identify the content there is nothing to contradict the caller, so
     * a permitted declared type is still accepted. Attachments in a password manager are often
     * keystores and certificates, which have no signature to match.
     *
     * @return void
     */
    #[DataProvider('inconclusiveProvider')]
    public function testDeclaredTypeIsAcceptedWhenDetectionIsInconclusive(string $detected): void
    {
        self::assertSame(
            'application/x-pkcs12',
            AllowedMimeType::resolve($detected, 'application/x-pkcs12', self::ALLOWED)
        );
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function inconclusiveProvider(): array
    {
        return [
            ['application/octet-stream'],
            ['text/plain'],
        ];
    }

    /**
     * An inconclusive detection is not a free pass either — the declared type still has to be
     * on the list.
     */
    public function testInconclusiveDetectionStillRequiresAnAllowedDeclaredType(): void
    {
        self::assertNull(
            AllowedMimeType::resolve('application/octet-stream', 'application/x-msdownload', self::ALLOWED)
        );
    }

    public function testNothingIsAllowedWhenTheListIsEmpty(): void
    {
        self::assertNull(AllowedMimeType::resolve('application/pdf', 'application/pdf', []));
    }
}
