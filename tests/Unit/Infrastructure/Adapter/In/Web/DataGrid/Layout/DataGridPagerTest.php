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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\DataGrid\Layout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Core\UI\IconInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionSearch;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridPager;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class DataGridPagerTest
 *
 * The pager decides which page of a listing the user is on and how many there are. It had no
 * tests, and its arithmetic is the sort that is wrong by one without anything failing loudly.
 */
#[Group('unitary')]
class DataGridPagerTest extends UnitaryTestCase
{
    /**
     * The page a given offset falls on, counting from one.
     */
    #[DataProvider('firstPageProvider')]
    public function testTheCurrentPageIsDerivedFromTheOffset(int $start, int $count, int $expected): void
    {
        $pager = (new DataGridPager())->setLimitStart($start)->setLimitCount($count);

        self::assertSame($expected, $pager->getFirstPage());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function firstPageProvider(): array
    {
        return [
            'the start of the first page' => [0, 10, 1],
            'the end of the first page' => [9, 10, 1],
            'the start of the second page' => [10, 10, 2],
            'the start of the third page' => [20, 10, 3],
            'a page size of one' => [4, 1, 5],
        ];
    }

    /**
     * How many pages the result set fills, so the last page is reachable and no empty one is
     * offered past it.
     */
    #[DataProvider('lastPageProvider')]
    public function testTheNumberOfPagesCoversTheResultSet(int $total, int $count, int $expected): void
    {
        $pager = (new DataGridPager())->setLimitCount($count)->setTotalRows($total);

        self::assertSame($expected, $pager->getLastPage());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function lastPageProvider(): array
    {
        return [
            'an exact fit' => [30, 10, 3],
            'a partial final page still counts' => [31, 10, 4],
            'fewer rows than a page' => [3, 10, 1],
            'no rows at all' => [0, 10, 0],
            'one row over' => [11, 10, 2],
        ];
    }

    /**
     * A page size of zero would divide by zero, so both calculations fall back to a single page
     * rather than raising.
     */
    public function testAPageSizeOfZeroIsTreatedAsASinglePage(): void
    {
        $pager = (new DataGridPager())->setLimitCount(0)->setTotalRows(50)->setLimitStart(10);

        self::assertSame(1, $pager->getFirstPage());
        self::assertSame(1, $pager->getLastPage());
    }

    /**
     * The sort key and direction are carried so a listing keeps its ordering across pages.
     */
    public function testTheSortingIsCarried(): void
    {
        $pager = (new DataGridPager())->setSortKey(3)->setSortOrder(1);

        self::assertSame(3, $pager->getSortKey());
        self::assertSame(1, $pager->getSortOrder());
    }

    /**
     * Whether a filter is applied is carried too, since a filtered listing pages through its
     * matches rather than everything.
     */
    public function testWhetherAFilterIsAppliedIsCarried(): void
    {
        self::assertFalse((new DataGridPager())->getFilterOn());
        self::assertTrue((new DataGridPager())->setFilterOn(true)->getFilterOn());
    }

    /**
     * The navigation icons are settable, since a theme supplies its own.
     *
     * @throws Exception
     */
    public function testTheNavigationIconsAreSettable(): void
    {
        $icon = $this->createStub(IconInterface::class);

        $pager = (new DataGridPager())
            ->setIconFirst($icon)
            ->setIconPrev($icon)
            ->setIconNext($icon)
            ->setIconLast($icon);

        self::assertSame($icon, $pager->getIconFirst());
        self::assertSame($icon, $pager->getIconPrev());
        self::assertSame($icon, $pager->getIconNext());
        self::assertSame($icon, $pager->getIconLast());
    }

    /**
     * The offsets behind the four navigation buttons. These are what the next request pages from,
     * so an off-by-one here skips or repeats a row rather than failing.
     */
    #[DataProvider('navigationOffsetProvider')]
    public function testTheNavigationOffsets(int $start, int $count, int $total, string $button, int $expected): void
    {
        $pager = (new DataGridPager())->setLimitStart($start)->setLimitCount($count)->setTotalRows($total);

        self::assertSame($expected, $pager->{'get' . $button}());
    }

    /**
     * @return array<string, array{int, int, int, string, int}>
     */
    public static function navigationOffsetProvider(): array
    {
        return [
            'first always goes back to the top' => [40, 10, 95, 'First', 0],
            'next is a page further on' => [40, 10, 95, 'Next', 50],
            'prev is a page back' => [40, 10, 95, 'Prev', 30],
            // 95 rows in pages of ten: the last page starts at 90 and holds five.
            'last is the start of the final partial page' => [40, 10, 95, 'Last', 90],
            // An exact fit has no partial page, so the last one starts a full page from the end.
            'last is a whole page back when the rows fit exactly' => [40, 10, 90, 'Last', 80],
        ];
    }

    /**
     * The buttons are rendered as a javascript call, and the offset is appended as its last
     * argument — that is how the page the button leads to is expressed.
     */
    public function testEachButtonCallsTheFunctionWithItsOffset(): void
    {
        $pager = (new DataGridPager())
            ->setOnClickFunction('account/search')
            ->setLimitStart(40)
            ->setLimitCount(10)
            ->setTotalRows(95);

        self::assertSame('account/search(0)', $pager->getOnClickFirst());
        self::assertSame('account/search(30)', $pager->getOnClickPrev());
        self::assertSame('account/search(50)', $pager->getOnClickNext());
        self::assertSame('account/search(90)', $pager->getOnClickLast());
    }

    /**
     * Arguments set on the pager come before the offset, and a non-numeric one is quoted so it
     * reaches the function as a string rather than as an identifier the browser would evaluate.
     */
    public function testTheConfiguredArgumentsArePassedAheadOfTheOffset(): void
    {
        $pager = (new DataGridPager())
            ->setOnClickFunction('account/search')
            ->setOnClickArgs('this')
            ->setOnClickArgs('tblAccounts')
            ->setOnClickArgs('7')
            ->setLimitStart(10)
            ->setLimitCount(10)
            ->setTotalRows(50);

        // 'this' is the element itself and 7 is a number, so neither is quoted.
        self::assertSame("account/search(this,'tblAccounts',7,20)", $pager->getOnClickNext());
    }

    /**
     * With no offset appended — the plain call, used where the button is not a page jump.
     */
    public function testThePlainCallCarriesOnlyTheConfiguredArguments(): void
    {
        $pager = (new DataGridPager())->setOnClickFunction('account/search')->setOnClickArgs('tblAccounts');

        self::assertSame("account/search('tblAccounts')", $pager->getOnClick());
    }

    /**
     * And with no arguments at all it is the bare function name, not a call with empty parentheses
     * the browser would choke on.
     */
    public function testThePlainCallWithoutArgumentsIsJustTheFunction(): void
    {
        self::assertSame('account/search', (new DataGridPager())->setOnClickFunction('account/search')->getOnClick());
    }

    /**
     * The pager carries the search action it belongs to, which is what the template submits when a
     * page button is pressed.
     *
     * @throws Exception
     */
    public function testThePagerCarriesTheSearchActionItPagesThrough(): void
    {
        $action = new DataGridActionSearch();

        self::assertSame($action, (new DataGridPager())->setSourceAction($action)->getSourceAction());
    }
}
