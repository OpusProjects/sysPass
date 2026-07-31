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

namespace SP\Tests\Unit\Domain\Account\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Account\Models\AccountPermissionItem;
use SP\Domain\Common\Models\Item;

/**
 * The account permission queries select isEdit — and, for users, login — on top of id/name.
 * PDO::FETCH_CLASS assigns every selected column, and Model::__set() throws for anything the model
 * does not declare, so mapping those rows onto plain Item made viewing an account that was shared
 * with anybody fail outright with "Dynamic properties not allowed".
 */
#[Group('unitary')]
class AccountPermissionItemTest extends TestCase
{
    /**
     * PDO::FETCH_CLASS assigns declared properties directly and routes everything else through
     * Model::__set(), which throws. So the mapped model has to *declare* every column the
     * permission queries select — getCols() is exactly that set.
     */
    public function testDeclaresEveryColumnThePermissionQueriesSelect(): void
    {
        $cols = AccountPermissionItem::getCols();

        foreach (['id', 'name', 'login', 'isEdit'] as $column) {
            self::assertContains($column, $cols);
        }
    }

    public function testExposesTheSelectedValues(): void
    {
        $item = new AccountPermissionItem(['id' => 7, 'name' => 'a_group', 'login' => 'a_login', 'isEdit' => 1]);

        self::assertSame(7, $item->getId());
        self::assertSame('a_group', $item->getName());
        self::assertSame('a_login', $item->getLogin());
        self::assertSame(1, $item->getIsEdit());
    }

    /**
     * The account DTOs are typed against Item[] and filter on `instanceof Item`, so the dedicated
     * model has to remain one.
     */
    public function testIsAnItem(): void
    {
        self::assertInstanceOf(Item::class, new AccountPermissionItem());
    }

    /**
     * The extra columns stay off the generic model, which backs the category/client/tag select
     * lists that have no such fields — declaring them there is what this fix deliberately avoids.
     */
    public function testPlainItemDoesNotDeclareThePermissionColumns(): void
    {
        self::assertSame(['id', 'name'], Item::getCols());
    }
}
