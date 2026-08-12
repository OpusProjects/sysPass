<?php
declare(strict_types=1);
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

namespace SP\Tests\Unit\Domain\Account\Models;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Models\AccountSearchView;
use SP\Tests\Support\UnitaryTestCase;

/**
 * AccountSearchView is what backs every row of the account listing — it is read from the
 * `account_search_v` database view, so unlike most models it is never built by a repository's
 * insert/update path, only ever hydrated from a query result. A getter reading the wrong column
 * would put the wrong client, category, owner or public-link expiry on a row without anything
 * failing: the page would just render one account as if it were another.
 *
 * Each accessor is asserted against a distinct value so a swapped column shows up immediately.
 */
#[Group('unitary')]
class AccountSearchViewTest extends UnitaryTestCase
{
    /**
     * A row from the search view, with a distinct value per column.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'id' => 1,
        'clientId' => 2,
        'categoryId' => 3,
        'name' => 'An account',
        'login' => 'a-login',
        'url' => 'https://example.invalid',
        'notes' => 'some notes',
        'userId' => 4,
        'userGroupId' => 5,
        'otherUserEdit' => 0,
        'otherUserGroupEdit' => 1,
        'isPrivate' => 1,
        'isPrivateGroup' => 0,
        'passDate' => 1700000000,
        'passDateChange' => 1800000000,
        'parentId' => 6,
        'countView' => 7,
        'dateEdit' => '2024-02-03 04:05:06',
        'userName' => 'Alice Example',
        'userLogin' => 'alice',
        'userGroupName' => 'Admins',
        'categoryName' => 'Servers',
        'clientName' => 'A client',
        'num_files' => 8,
        'publicLinkHash' => 'abc123',
        'publicLinkDateExpire' => 1900000000,
        'publicLinkTotalCountViews' => 9,
    ];

    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnColumn(string $accessor, string $column): void
    {
        self::assertSame(self::ROW[$column], (new AccountSearchView(self::ROW))->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getId' => 'id',
            'getClientId' => 'clientId',
            'getCategoryId' => 'categoryId',
            'getName' => 'name',
            'getLogin' => 'login',
            'getUrl' => 'url',
            'getNotes' => 'notes',
            'getUserId' => 'userId',
            'getUserGroupId' => 'userGroupId',
            'getOtherUserEdit' => 'otherUserEdit',
            'getOtherUserGroupEdit' => 'otherUserGroupEdit',
            'getIsPrivate' => 'isPrivate',
            'getIsPrivateGroup' => 'isPrivateGroup',
            'getPassDate' => 'passDate',
            'getPassDateChange' => 'passDateChange',
            'getParentId' => 'parentId',
            'getCountView' => 'countView',
            'getDateEdit' => 'dateEdit',
            'getUserName' => 'userName',
            'getUserLogin' => 'userLogin',
            'getUserGroupName' => 'userGroupName',
            'getCategoryName' => 'categoryName',
            'getClientName' => 'clientName',
            'getNumFiles' => 'num_files',
            'getPublicLinkHash' => 'publicLinkHash',
            'getPublicLinkDateExpire' => 'publicLinkDateExpire',
            'getPublicLinkTotalCountViews' => 'publicLinkTotalCountViews',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $column) {
            $cases[$accessor] = [$accessor, $column];
        }

        return $cases;
    }

    /**
     * Every column is nullable, and a row built from nothing reads as nothing rather than
     * raising -- the model is what an incomplete query result is hydrated into, and the search
     * listing has to render a row it knows little about instead of fatally erroring.
     */
    #[Test]
    public function anEmptyRowReadsAsNothing(): void
    {
        $row = new AccountSearchView();

        self::assertNull($row->getId());
        self::assertNull($row->getName());
        self::assertNull($row->getClientName());
        self::assertNull($row->getPublicLinkHash());
        self::assertNull($row->getNumFiles());
    }
}
