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

namespace SP\Tests\Unit\Domain\Common\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Common\Providers\Version;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class VersionTest
 *
 * Version comparison decides whether an upgrade runs. Getting it wrong in one direction skips a
 * needed migration and in the other re-runs one, so the boundaries are what matter.
 */
#[Group('unitary')]
class VersionTest extends UnitaryTestCase
{
    /**
     * True means "the first version is older", i.e. an upgrade is needed.
     */
    #[DataProvider('comparisonProvider')]
    public function testAnOlderVersionIsRecognisedAsNeedingAnUpgrade(
        string $current,
        string $published,
        bool $needsUpgrade
    ): void {
        self::assertSame($needsUpgrade, Version::checkVersion($current, $published));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function comparisonProvider(): array
    {
        return [
            'a newer major' => ['300.19012201', '400.19012201', true],
            'an older major' => ['400.19012201', '300.19012201', false],
            'the same version' => ['400.19012201', '400.19012201', false],
            'the same version, a newer build' => ['400.19012201', '400.19012202', true],
            'the same version, an older build' => ['400.19012202', '400.19012201', false],
            'a newer minor' => ['400.19012201', '410.19012201', true],
            'a newer patch' => ['400.19012201', '401.19012201', true],
        ];
    }

    /**
     * The published version may arrive as a list, in which case the last entry is the one
     * compared — that is how the update check hands over what it fetched.
     */
    public function testAListOfVersionsComparesAgainstItsLastEntry(): void
    {
        self::assertTrue(Version::checkVersion('300.19012201', ['100.19012201', '400.19012201']));
        self::assertFalse(Version::checkVersion('400.19012201', ['400.19012201', '300.19012201']));
    }

    /**
     * A version that cannot be read is not treated as newer. Answering true would trigger an
     * upgrade against a version nobody could parse.
     */
    #[DataProvider('unreadableVersionProvider')]
    public function testAnUnreadableVersionDoesNotTriggerAnUpgrade(string $current, string $published): void
    {
        self::assertFalse(Version::checkVersion($current, $published));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unreadableVersionProvider(): array
    {
        return [
            'an empty published version' => ['400.19012201', ''],
            'an empty current version' => ['', '400.19012201'],
            'both empty' => ['', ''],
        ];
    }

    /**
     * Normalisation turns a version into something comparable, and reports nothing for an empty
     * input rather than a zero that would compare as the oldest possible version.
     */
    public function testNormalisationReportsNothingForAnEmptyInput(): void
    {
        self::assertNull(Version::normalizeVersionForCompare(null));
        self::assertNull(Version::normalizeVersionForCompare(''));
        self::assertNull(Version::normalizeVersionForCompare([]));
    }

    /**
     * A four-part version arrives as an array from the config, and normalises to the same thing
     * its string form does.
     */
    public function testAFourPartArrayNormalisesLikeItsStringForm(): void
    {
        self::assertSame(
            Version::normalizeVersionForCompare('400.19012201'),
            Version::normalizeVersionForCompare([4, 0, 0, '19012201'])
        );
    }

    /**
     * An array that is not four parts cannot be read as a version, so it normalises to nothing
     * comparable rather than to a partial number.
     */
    public function testAnArrayOfTheWrongShapeNormalisesToNothingComparable(): void
    {
        self::assertSame('', Version::normalizeVersionForCompare([4, 0]));
    }

    /**
     * The running version is reported in both forms the application uses.
     */
    public function testTheRunningVersionIsReportedInBothForms(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+$/', Version::getVersionStringNormalized());

        $asArray = Version::getVersionArray();

        self::assertNotEmpty($asArray);
        self::assertCount(3, $asArray);
        self::assertCount(4, Version::getVersionArray(true), 'with the build appended');
    }

    /**
     * The integer form is what the database schema version is stored and compared as.
     */
    public function testAVersionConvertsToAComparableInteger(): void
    {
        self::assertGreaterThan(
            Version::versionToInteger('3.2.0'),
            Version::versionToInteger('4.0.0')
        );
        self::assertSame(Version::versionToInteger('4.0.0'), Version::versionToInteger('4.0.0'));
    }
}
