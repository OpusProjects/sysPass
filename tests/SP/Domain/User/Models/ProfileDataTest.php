<?php

declare(strict_types=1);

namespace SP\Tests\Domain\User\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\User\Models\ProfileData;

#[Group('unitary')]
class ProfileDataTest extends TestCase
{
    private const ALL_PERMISSIONS = [
        'accView',
        'accViewPass',
        'accViewHistory',
        'accEdit',
        'accEditPass',
        'accAdd',
        'accDelete',
        'accFiles',
        'accPrivate',
        'accPrivateGroup',
        'accPermission',
        'accPublicLinks',
        'accGlobalSearch',
        'configGeneral',
        'configEncryption',
        'configBackup',
        'configImport',
        'mgmUsers',
        'mgmGroups',
        'mgmProfiles',
        'mgmCategories',
        'mgmCustomers',
        'mgmApiTokens',
        'mgmPublicLinks',
        'mgmAccounts',
        'mgmTags',
        'mgmFiles',
        'mgmItemsPreset',
        'evl',
        'mgmCustomFields',
    ];

    public function testDefaultsAllFalse(): void
    {
        $profile = new ProfileData();

        foreach (self::ALL_PERMISSIONS as $perm) {
            $getter = str_starts_with($perm, 'is') ? $perm : 'is' . ucfirst($perm);
            self::assertFalse($profile->$getter(), "$perm should default to false");
        }
    }

    public function testConstructorSetsProperties(): void
    {
        $profile = new ProfileData([
            'accView' => true,
            'mgmUsers' => true,
            'evl' => true,
        ]);

        self::assertTrue($profile->isAccView());
        self::assertTrue($profile->isMgmUsers());
        self::assertTrue($profile->isEvl());
        self::assertFalse($profile->isAccEdit());
    }

    public function testSettersAreFluent(): void
    {
        $profile = new ProfileData();

        $result = $profile->setAccView(true);

        self::assertSame($profile, $result);
        self::assertTrue($profile->isAccView());
    }

    public function testSettersMutateState(): void
    {
        $profile = new ProfileData();

        $profile->setAccView(true)
            ->setAccEdit(true)
            ->setMgmUsers(true)
            ->setConfigGeneral(true);

        self::assertTrue($profile->isAccView());
        self::assertTrue($profile->isAccEdit());
        self::assertTrue($profile->isMgmUsers());
        self::assertTrue($profile->isConfigGeneral());
        self::assertFalse($profile->isAccDelete());
    }

    public function testToArrayIncludesAllPermissions(): void
    {
        $profile = new ProfileData();
        $array = $profile->toArray();

        foreach (self::ALL_PERMISSIONS as $perm) {
            self::assertArrayHasKey($perm, $array);
        }
    }

    public function testMutateCreatesNewInstance(): void
    {
        $original = new ProfileData(['accView' => true]);
        $mutated = $original->mutate(['accEdit' => true]);

        self::assertNotSame($original, $mutated);
        self::assertTrue($original->isAccView());
        self::assertFalse($original->isAccEdit());
        self::assertTrue($mutated->isAccView());
        self::assertTrue($mutated->isAccEdit());
    }

    public function testSerializationRoundTrip(): void
    {
        $profile = new ProfileData();
        $profile->setAccView(true)
            ->setMgmUsers(true)
            ->setEvl(true);

        $serialized = serialize($profile);
        $restored = unserialize($serialized);

        self::assertInstanceOf(ProfileData::class, $restored);
        self::assertTrue($restored->isAccView());
        self::assertTrue($restored->isMgmUsers());
        self::assertTrue($restored->isEvl());
        self::assertFalse($restored->isAccDelete());
    }

    public function testWakeupRenamesUnderscorePrefixedProperties(): void
    {
        $profile = new ProfileData();
        $profile->setAccView(true);

        $serialized = serialize($profile);
        $tampered = str_replace('"accView"', '"_accView"', $serialized);
        $restored = unserialize($tampered);

        self::assertTrue($restored->isAccView());
    }

    public function testJsonSerialize(): void
    {
        $profile = new ProfileData(['accView' => true, 'mgmUsers' => true]);

        $json = json_encode($profile);
        $decoded = json_decode($json, true);

        self::assertTrue($decoded['accView']);
        self::assertTrue($decoded['mgmUsers']);
        self::assertFalse($decoded['accEdit']);
    }
}
