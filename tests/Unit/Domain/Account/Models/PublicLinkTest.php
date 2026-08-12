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
use SP\Domain\Account\Models\PublicLink;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A PublicLink is the row behind an anonymous, tokenised URL that hands a viewer one account's
 * password without a login — its hash is the secret in that URL, and its view/expiry counters
 * are what a token is throttled and expired against. A getter reading the wrong column would let
 * a link outlive its expiry, miscount its views, or leak the wrong account's name, without
 * anything failing.
 *
 * Two columns behave in a way worth pinning down on their own: getId()/getItemId()/getUserId()
 * (int)-cast the stored value, so an unset link reports 0 rather than null even though getId()'s
 * own signature says ?int; and getName() is hard-wired to null (a public link is not itself
 * named — its label is the account it points at), so it stays null even if a 'name' value is
 * fed into the row.
 */
#[Group('unitary')]
class PublicLinkTest extends UnitaryTestCase
{
    /**
     * A stored public-link row, with a distinct value per column.
     *
     * @return array<string, mixed>
     */
    private const ROW = [
        'id' => 1,
        'itemId' => 2,
        'hash' => 'a1b2c3d4',
        'userId' => 3,
        'typeId' => 4,
        'notify' => true,
        'dateAdd' => 1700000000,
        'dateUpdate' => 1700000100,
        'dateExpire' => 1800000000,
        'countViews' => 5,
        'totalCountViews' => 6,
        'maxCountViews' => 7,
        'useInfo' => 'a-serialized-use-info-blob',
        'data' => 'the-encrypted-payload',
        'userName' => 'Alice Example',
        'userLogin' => 'alice',
        'accountName' => 'An account',
        'clientName' => 'A client',
    ];

    #[Test]
    #[DataProvider('accessorProvider')]
    public function eachAccessorReadsItsOwnColumn(string $accessor, string $column): void
    {
        self::assertSame(self::ROW[$column], (new PublicLink(self::ROW))->{$accessor}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accessorProvider(): array
    {
        $accessors = [
            'getId' => 'id',
            'getItemId' => 'itemId',
            'getHash' => 'hash',
            'getUserId' => 'userId',
            'getTypeId' => 'typeId',
            'isNotify' => 'notify',
            'getDateAdd' => 'dateAdd',
            'getDateUpdate' => 'dateUpdate',
            'getDateExpire' => 'dateExpire',
            'getCountViews' => 'countViews',
            'getTotalCountViews' => 'totalCountViews',
            'getMaxCountViews' => 'maxCountViews',
            'getUseInfo' => 'useInfo',
            'getData' => 'data',
            'getUserName' => 'userName',
            'getUserLogin' => 'userLogin',
            'getAccountName' => 'accountName',
            'getClientName' => 'clientName',
        ];

        $cases = [];

        foreach ($accessors as $accessor => $column) {
            $cases[$accessor] = [$accessor, $column];
        }

        return $cases;
    }

    /**
     * Every column is nullable, and a row built from nothing reads as nothing for the plain
     * pass-through getters and as "not notifying" rather than raising for the boolean one — the
     * model is what an incomplete query result is hydrated into.
     */
    #[Test]
    public function anEmptyLinkReadsAsNothing(): void
    {
        $link = new PublicLink();

        self::assertNull($link->getHash());
        self::assertNull($link->getTypeId());
        self::assertNull($link->getDateExpire());
        self::assertNull($link->getUseInfo());
        self::assertFalse($link->isNotify(), 'no notify setting recorded is treated as off, not unknown');
    }

    /**
     * getId()/getItemId()/getUserId() (int)-cast the stored property instead of returning it as
     * given. For a link that was never persisted (no id assigned yet) that collapses null to 0,
     * so code deciding "is this a new link" by comparing the id to null would see 0 and treat it
     * as an existing one instead.
     */
    #[Test]
    public function anUnsetIdItemIdAndUserIdCollapseToZeroRatherThanNull(): void
    {
        $link = new PublicLink();

        self::assertSame(0, $link->getId());
        self::assertSame(0, $link->getItemId());
        self::assertSame(0, $link->getUserId());
    }

    /**
     * PublicLink implements ItemWithIdAndNameModel, which requires a getName(), but a link has
     * no name column of its own -- getName() is hard-wired to null rather than reading a
     * property. That has to hold even when a row happens to carry a 'name' value (it lands in
     * the model's outer property bag, readable through ArrayAccess, but getName() still ignores
     * it), otherwise a listing built against the ItemWithIdAndNameModel contract would show a
     * stray label instead of the blank it is meant to render.
     */
    #[Test]
    public function theNameIsAlwaysNothingEvenWhenARowCarriesOne(): void
    {
        $link = new PublicLink(['name' => 'should be ignored'] + self::ROW);

        self::assertNull($link->getName());
        self::assertSame('should be ignored', $link['name'], 'the outer bag still carries it');
        self::assertSame('An account', $link->getAccountName(), 'the real label is the account name');
    }
}
