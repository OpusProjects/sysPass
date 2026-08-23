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
use SP\Domain\Account\Models\AccountHistoryView;
use SP\Tests\Support\UnitaryTestCase;

/**
 * AccountHistoryView enriches a plain AccountHistory row with the owner's, the group's and the
 * editor's names for the history detail screen -- AccountHistory's own columns are already
 * covered by AccountHistoryTest, so this covers only the four accessors this subclass adds. A
 * getter reading the wrong column here would put one person's name on another's history entry
 * without anything failing.
 */
#[Group('unitary')]
class AccountHistoryViewTest extends UnitaryTestCase
{
    /**
     * The enriched columns, with a distinct value per column so a swapped getter shows up
     * immediately.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'userName' => 'Alice Example',
        'userGroupName' => 'Admins',
        'userEditName' => 'Bob Example',
        'userEditLogin' => 'bob-login',
    ];

    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnColumn(string $accessor, string $column): void
    {
        self::assertSame(self::ROW[$column], (new AccountHistoryView(self::ROW))->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getUserName' => 'userName',
            'getUserGroupName' => 'userGroupName',
            'getUserEditName' => 'userEditName',
            'getUserEditLogin' => 'userEditLogin',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $column) {
            $cases[$accessor] = [$accessor, $column];
        }

        return $cases;
    }

    /**
     * These four columns are nullable like the rest of the row, and a view built from an
     * incomplete result (e.g. a history entry whose editor was later deleted) must read as
     * nothing rather than raising.
     */
    #[Test]
    public function anEmptyRowReadsAsNothing(): void
    {
        $view = new AccountHistoryView();

        self::assertNull($view->getUserName());
        self::assertNull($view->getUserGroupName());
        self::assertNull($view->getUserEditName());
        self::assertNull($view->getUserEditLogin());
    }
}
