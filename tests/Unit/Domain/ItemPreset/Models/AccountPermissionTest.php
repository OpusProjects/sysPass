<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\ItemPreset\Models\AccountPermission;

#[Group('unitary')]
class AccountPermissionTest extends TestCase
{
    public function testConstructorSetsAllArrays(): void
    {
        $perm = new AccountPermission(
            usersView: [1, 2],
            usersEdit: [3],
            userGroupsView: [10, 20],
            userGroupsEdit: [30],
        );

        self::assertSame([1, 2], $perm->getUsersView());
        self::assertSame([3], $perm->getUsersEdit());
        self::assertSame([10, 20], $perm->getUserGroupsView());
        self::assertSame([30], $perm->getUserGroupsEdit());
    }

    public function testHasItemsReturnsTrueWhenAnyArrayIsNonEmpty(): void
    {
        self::assertTrue(
            (new AccountPermission([1], [], [], []))->hasItems()
        );
        self::assertTrue(
            (new AccountPermission([], [2], [], []))->hasItems()
        );
        self::assertTrue(
            (new AccountPermission([], [], [3], []))->hasItems()
        );
        self::assertTrue(
            (new AccountPermission([], [], [], [4]))->hasItems()
        );
    }

    public function testHasItemsReturnsFalseWhenAllEmpty(): void
    {
        $perm = new AccountPermission([], [], [], []);

        self::assertFalse($perm->hasItems());
    }

    public function testIsReadonly(): void
    {
        $perm = new AccountPermission([1], [2], [3], [4]);

        self::expectException(\Error::class);
        $perm->usersView = [99];
    }
}
