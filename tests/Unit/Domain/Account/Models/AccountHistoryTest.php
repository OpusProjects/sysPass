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

namespace SP\Tests\Unit\Domain\Account\Models;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Models\AccountHistory;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A history row is what an account is restored from, and it is read back through these accessors —
 * including the encrypted password, its key, and the master password hash the pair belongs to.
 *
 * A getter reading the wrong column would restore an account with somebody else's data, or with a
 * password that cannot be decrypted, without anything failing. Each is asserted against a distinct
 * value so that is visible.
 */
#[Group('unitary')]
class AccountHistoryTest extends UnitaryTestCase
{
    /**
     * A stored history row, with a distinct value per column.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'id' => 1,
        'accountId' => 2,
        'userId' => 3,
        'userGroupId' => 4,
        'userEditId' => 5,
        'clientId' => 6,
        'categoryId' => 7,
        'countView' => 8,
        'countDecrypt' => 9,
        'isPrivate' => 1,
        'isPrivateGroup' => 0,
        'passDate' => 1700000000,
        'passDateChange' => 1800000000,
        'parentId' => 10,
        'otherUserGroupEdit' => 1,
        'otherUserEdit' => 0,
        'isModify' => 1,
        'isDeleted' => 0,
        'name' => 'An account',
        'login' => 'a-login',
        'url' => 'https://example.invalid',
        'pass' => 'the-encrypted-pass',
        'key' => 'the-crypt-key',
        'notes' => 'some notes',
        'mPassHash' => 'the-master-password-hash',
        'dateAdd' => '2024-01-02 03:04:05',
        'dateEdit' => '2024-02-03 04:05:06',
    ];

    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnColumn(string $accessor, string $column)
    {
        self::assertSame(self::ROW[$column], (new AccountHistory(self::ROW))->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getId' => 'id',
            'getAccountId' => 'accountId',
            'getUserId' => 'userId',
            'getUserGroupId' => 'userGroupId',
            'getUserEditId' => 'userEditId',
            'getClientId' => 'clientId',
            'getCategoryId' => 'categoryId',
            'getCountView' => 'countView',
            'getCountDecrypt' => 'countDecrypt',
            'getIsPrivate' => 'isPrivate',
            'getIsPrivateGroup' => 'isPrivateGroup',
            'getPassDate' => 'passDate',
            'getPassDateChange' => 'passDateChange',
            'getParentId' => 'parentId',
            'getOtherUserGroupEdit' => 'otherUserGroupEdit',
            'getOtherUserEdit' => 'otherUserEdit',
            'getIsModify' => 'isModify',
            'getIsDeleted' => 'isDeleted',
            'getName' => 'name',
            'getLogin' => 'login',
            'getUrl' => 'url',
            'getPass' => 'pass',
            'getKey' => 'key',
            'getNotes' => 'notes',
            'getMPassHash' => 'mPassHash',
            'getDateAdd' => 'dateAdd',
            'getDateEdit' => 'dateEdit',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $column) {
            $cases[$accessor] = [$accessor, $column];
        }

        return $cases;
    }

    /**
     * Every column is nullable, and a row built from nothing reads as nothing rather than raising —
     * the model is what an incomplete query result is hydrated into.
     */
    #[Test]
    public function anEmptyRowReadsAsNothing()
    {
        $history = new AccountHistory();

        self::assertNull($history->getId());
        self::assertNull($history->getPass());
        self::assertNull($history->getMPassHash());
    }
}
