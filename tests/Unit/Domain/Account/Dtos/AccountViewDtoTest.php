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

namespace SP\Tests\Unit\Domain\Account\Dtos;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Dtos\AccountViewDto;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The public-link page is built from this. The account is deserialized out of the link's vault as a
 * plain row and turned into the Dto by name, so a field the constructor does not take a matching
 * name for comes out null and the page renders a blank where the value should be — nothing fails.
 *
 * Every accessor is therefore asserted against the row it was built from, which is the only thing
 * that catches that.
 */
#[Group('unitary')]
class AccountViewDtoTest extends UnitaryTestCase
{
    /**
     * The account as it is sealed into a public link, with a distinct value per field so a getter
     * reading the wrong one is visible.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'id' => 11,
        'name' => 'An account',
        'login' => 'a-login',
        'clientId' => 12,
        'categoryId' => 13,
        'pass' => 'the-encrypted-pass',
        'userId' => 14,
        'userName' => 'The owner',
        'key' => 'the-crypt-key',
        'url' => 'https://example.invalid',
        'notes' => 'some notes',
        'userEditId' => 15,
        'userEditName' => 'The last editor',
        'userEditLogin' => 'editor-login',
        'isPrivate' => true,
        'isPrivateGroup' => false,
        'userGroupId' => 16,
        'userGroupName' => 'The group',
        'otherUserEdit' => true,
        'otherUserGroupEdit' => false,
        'countView' => 17,
        'countDecrypt' => 18,
        'dateAdd' => '2024-01-02 03:04:05',
        'dateEdit' => '2024-02-03 04:05:06',
        'passDate' => 1700000000,
        'passDateChange' => 1800000000,
        'parentId' => 19,
        'usersView' => [21, 22],
        'usersEdit' => [23],
        'userGroupsView' => [24],
        'userGroupsEdit' => [25, 26],
        'tags' => [27],
        'categoryName' => 'The category',
        'clientName' => 'The client',
    ];

    /**
     * @throws SPException
     */
    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnField(string $accessor, string $field)
    {
        $dto = AccountViewDto::fromModel(new Simple(self::ROW));

        self::assertSame(self::ROW[$field], $dto->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getId' => 'id',
            'getName' => 'name',
            'getLogin' => 'login',
            'getClientId' => 'clientId',
            'getCategoryId' => 'categoryId',
            'getPass' => 'pass',
            'getUserId' => 'userId',
            'getUserName' => 'userName',
            'getKey' => 'key',
            'getUrl' => 'url',
            'getNotes' => 'notes',
            'getUserEditId' => 'userEditId',
            'getUserEditName' => 'userEditName',
            'getUserEditLogin' => 'userEditLogin',
            'isPrivate' => 'isPrivate',
            'isPrivateGroup' => 'isPrivateGroup',
            'getUserGroupId' => 'userGroupId',
            'getUserGroupName' => 'userGroupName',
            'isOtherUserEdit' => 'otherUserEdit',
            'isOtherUserGroupEdit' => 'otherUserGroupEdit',
            'getCountView' => 'countView',
            'getCountDecrypt' => 'countDecrypt',
            'getDateAdd' => 'dateAdd',
            'getDateEdit' => 'dateEdit',
            'getPassDate' => 'passDate',
            'getPassDateChange' => 'passDateChange',
            'getParentId' => 'parentId',
            'getUsersView' => 'usersView',
            'getUsersEdit' => 'usersEdit',
            'getUserGroupsView' => 'userGroupsView',
            'getUserGroupsEdit' => 'userGroupsEdit',
            'getTags' => 'tags',
            'getCategoryName' => 'categoryName',
            'getClientName' => 'clientName',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $field) {
            $cases[$accessor] = [$accessor, $field];
        }

        return $cases;
    }

    /**
     * A public link carries the account without its crypt key — the vault already holds the
     * decrypted password, and the key has no business in a blob anybody with the URL can fetch. The
     * optional fields are absent from an account that was never edited or shared, so the Dto has to
     * build without them rather than refusing the row.
     *
     * @throws SPException
     */
    #[Test]
    public function anAccountWithoutItsOptionalFieldsStillBuilds()
    {
        $dto = AccountViewDto::fromModel(
            new Simple(
                [
                    'id' => 11,
                    'name' => 'An account',
                    'login' => null,
                    'clientId' => 12,
                    'categoryId' => 13,
                    'pass' => 'the-encrypted-pass',
                    'userId' => 14,
                    'userName' => 'The owner',
                    'key' => null,
                    'url' => null,
                    'notes' => null,
                    'userEditId' => 15,
                    'userEditName' => 'The last editor',
                    'userEditLogin' => 'editor-login',
                    'isPrivate' => false,
                    'isPrivateGroup' => false,
                    'userGroupId' => 16,
                    'userGroupName' => 'The group',
                    'otherUserEdit' => false,
                    'otherUserGroupEdit' => false,
                    'countView' => 0,
                    'countDecrypt' => 0,
                    'dateAdd' => '2024-01-02 03:04:05',
                ]
            )
        );

        self::assertNull($dto->getKey());
        self::assertNull($dto->getLogin());
        self::assertNull($dto->getDateEdit());
        self::assertNull($dto->getTags());
        self::assertSame('An account', $dto->getName());
    }
}
