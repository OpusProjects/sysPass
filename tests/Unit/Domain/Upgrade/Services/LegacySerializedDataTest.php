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

namespace SP\Tests\Unit\Domain\Upgrade\Services;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\ItemPreset\Models\AccountPermission;
use SP\Domain\ItemPreset\Models\AccountPrivate;
use SP\Domain\ItemPreset\Models\ItemPreset;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Models\SessionTimeout;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Domain\Upgrade\Ports\LegacySerializedDataRepository;
use SP\Domain\Upgrade\Services\LegacySerializedData;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User;
use SP\Domain\User\Models\UserPreferences;
use SP\Domain\User\Models\UserProfile;
use SP\Tests\Support\UnitaryTestCase;

/**
 * What sysPass 3.2 stored, read by this version
 *
 * The blobs here are written the way 3.2 wrote them: its own class names, which this rewrite does
 * not have, and its protected and private property markers. Read directly, every one of them
 * failed with "Invalid target class".
 */
#[Group('unitary')]
class LegacySerializedDataTest extends UnitaryTestCase
{
    /**
     * @param array<string, mixed> $properties
     */
    private static function legacy(string $class, array $properties, string $visibility = 'protected'): string
    {
        $parts = '';

        foreach ($properties as $name => $value) {
            $key = match ($visibility) {
                'protected' => "\0*\0" . $name,
                'private' => "\0" . $class . "\0" . $name,
                default => $name,
            };
            $parts .= serialize($key) . serialize($value);
        }

        return sprintf('O:%d:"%s":%d:{%s}', strlen($class), $class, count($properties), $parts);
    }

    public function testA32ProfileFailedToReadBeforeConversion(): void
    {
        $blob = self::legacy('SP\DataModel\ProfileData', ['accView' => true]);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('Invalid target class');

        (new UserProfile(['profile' => $blob]))->hydrate(ProfileData::class);
    }

    public function testA32ProfileIsReadAfterConversion(): void
    {
        $blob = self::legacy(
            'SP\DataModel\ProfileData',
            ['accView' => true, 'accEdit' => true, 'mgmUsers' => false, 'aPropertyThisVersionDropped' => 1]
        );

        $json = LegacySerializedData::toJson($blob, ProfileData::class);
        $profile = (new UserProfile(['profile' => $json]))->hydrate(ProfileData::class);

        self::assertStringStartsWith('{', $json);
        self::assertTrue($profile->isAccView());
        self::assertTrue($profile->isAccEdit());
        self::assertFalse($profile->isMgmUsers());
        self::assertFalse($profile->isAccDelete(), 'a permission 3.2 did not store keeps its default');
    }

    public function testA32UserPreferencesIsReadAfterConversion(): void
    {
        $blob = self::legacy('SP\DataModel\UserPreferencesData', ['user_id' => 3, 'lang' => 'es_ES', 'theme' => 'material-blue'], 'public');

        $preferences = (new User(['preferences' => LegacySerializedData::toJson($blob, UserPreferences::class)]))
            ->hydrate(UserPreferences::class);

        self::assertSame('es_ES', $preferences->getLang());
        self::assertSame('material-blue', $preferences->getTheme());
    }

    public function testEach32PresetIsReadAfterConversion(): void
    {
        $password = self::legacy('SP\DataModel\ItemPreset\Password', [
            'length' => '16', 'useNumbers' => true, 'useLetters' => true, 'useSymbols' => false, 'useUpper' => true,
            'useLower' => true, 'useImage' => false, 'expireTime' => 7776000, 'score' => 3, 'regex' => '',
        ], 'private');
        $permission = self::legacy('SP\DataModel\ItemPreset\AccountPermission', [
            'usersView' => [2, 3], 'usersEdit' => [3], 'userGroupsView' => [1], 'userGroupsEdit' => [],
        ], 'private');
        $private = self::legacy('SP\DataModel\ItemPreset\AccountPrivate', ['privateUser' => true, 'privateGroup' => false], 'private');
        $timeout = self::legacy('SP\DataModel\ItemPreset\SessionTimeout', [
            'address' => '192.168.0.0', 'mask' => '255.255.255.0', 'timeout' => 900,
        ], 'private');

        $read = static fn(string $blob, string $class): object => (new ItemPreset(['data' => LegacySerializedData::toJson($blob, $class)]))
            ->hydrate($class);

        $p = $read($password, Password::class);
        self::assertSame(16, $p->getLength(), '3.2 was loose about scalar types; the length was a string');
        self::assertSame(7776000, $p->getExpireTime(), 'stored in seconds by both versions');

        self::assertSame([2, 3], $read($permission, AccountPermission::class)->getUsersView());
        self::assertTrue($read($private, AccountPrivate::class)->isPrivateUser());

        $t = $read($timeout, SessionTimeout::class);
        self::assertSame('192.168.0.0', $t->getAddress());
        self::assertSame('255.255.255.0', $t->getMask());
        self::assertSame(900, $t->getTimeout());
    }

    /**
     * No class named in the blob is instantiated, whatever it names.
     */
    public function testNoClassTheBlobNamesIsInstantiated(): void
    {
        $blob = self::legacy(\ArrayObject::class, ['accView' => true]);

        $profile = (new UserProfile(['profile' => LegacySerializedData::toJson($blob, ProfileData::class)]))
            ->hydrate(ProfileData::class);

        self::assertTrue($profile->isAccView());
    }

    public function testConvertsEveryLegacyRowAndLeavesTheRestAlone(): void
    {
        $profile = self::legacy('SP\DataModel\ProfileData', ['accView' => true]);
        $preset = self::legacy('SP\DataModel\ItemPreset\AccountPrivate', ['privateUser' => true], 'private');

        $repository = $this->createStub(LegacySerializedDataRepository::class);
        $repository->method('findNotJson')->willReturnCallback(
            static fn(string $table): array => match ($table) {
                'UserProfile' => [['id' => 1, 'value' => $profile, 'type' => null]],
                'ItemPreset' => [
                    ['id' => 5, 'value' => $preset, 'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE],
                    ['id' => 6, 'value' => $preset, 'type' => 'no.such.type'],
                ],
                default => [],
            }
        );

        $written = [];
        $repository->method('replace')->willReturnCallback(
            static function (string $table, string $column, int $id, string $expected, string $json) use (&$written): bool {
                $written[] = [$table, $column, $id];

                return str_starts_with($json, '{');
            }
        );

        $result = (new LegacySerializedData($repository))->convert();

        self::assertSame(['converted' => 2, 'failed' => 1], $result);
        self::assertSame([['UserProfile', 'profile', 1], ['ItemPreset', 'data', 5]], $written);
    }
}
