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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Account\Ports\AccountSearchConstants;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The filter behind an account listing. It is kept in the session between requests, which is what
 * makes clearing it matter: a filter that survives being reset would keep narrowing later searches
 * to a client or a category the user thinks they have cleared, and they would read the shorter
 * listing as "there are no other accounts".
 */
#[Group('unitary')]
class AccountSearchFilterDtoTest extends UnitaryTestCase
{
    /**
     * Clearing the filter puts every part of it back, not only the search box.
     */
    #[Test]
    public function clearingTheFilterClearsEveryPartOfIt()
    {
        $filter = $this->buildFilledFilter();

        $filter->reset();

        self::assertNull($filter->getTxtSearch());
        self::assertNull($filter->getCleanTxtSearch());
        self::assertNull($filter->getClientId());
        self::assertNull($filter->getCategoryId());
        self::assertNull($filter->getTagsId());
        // The operator falls back to AND rather than to nothing: a cleared filter still has to
        // combine its terms somehow, and OR would widen every later search.
        self::assertSame(AccountSearchConstants::FILTER_CHAIN_AND, $filter->getFilterOperator());
        self::assertNull($filter->getLimitCount());
        self::assertFalse($filter->isSortViews());
        self::assertFalse($filter->getGlobalSearch());
        self::assertFalse($filter->isSearchFavorites());
    }

    /**
     * And puts the ordering back to the default rather than leaving the listing sorted by whatever
     * was last chosen.
     */
    #[Test]
    public function clearingTheFilterRestoresTheDefaultOrdering()
    {
        $filter = $this->buildFilledFilter();

        $filter->reset();

        self::assertSame(AccountSearchConstants::SORT_DEFAULT, $filter->getSortKey());
        self::assertSame(AccountSearchConstants::SORT_DIR_ASC, $filter->getSortOrder());
    }

    /**
     * The row count of the last query is held statically — one listing's count would otherwise be
     * shown against another's results — so clearing the filter clears that too.
     */
    #[Test]
    public function clearingTheFilterForgetsTheLastRowCount()
    {
        AccountSearchFilterDto::$queryNumRows = 42;

        (new AccountSearchFilterDto())->reset();

        self::assertNull(AccountSearchFilterDto::$queryNumRows);
    }

    /**
     * Building from a search term is how a search from the box starts.
     */
    #[Test]
    public function aFilterIsBuiltFromTheSearchTerm()
    {
        self::assertSame('a term', AccountSearchFilterDto::build('a term')->getTxtSearch());
    }

    /**
     * An empty search box is not a search for the empty string.
     */
    #[Test]
    public function anEmptySearchTermIsCarriedAsNothing()
    {
        self::assertNull(AccountSearchFilterDto::build(null)->getTxtSearch());
    }

    /**
     * The paging window is what the next page is read from, so both halves are carried.
     */
    #[Test]
    public function thePagingWindowIsCarried()
    {
        $filter = (new AccountSearchFilterDto())->setLimitStart(30)->setLimitCount(15);

        self::assertSame(30, $filter->getLimitStart());
        self::assertSame(15, $filter->getLimitCount());
    }

    private function buildFilledFilter(): AccountSearchFilterDto
    {
        $filter = AccountSearchFilterDto::build('a term');
        $filter->setCleanTxtSearch('a term');
        $filter->setClientId(1);
        $filter->setCategoryId(2);
        $filter->setTagsId([3, 4]);
        $filter->setFilterOperator(AccountSearchConstants::FILTER_CHAIN_OR);
        $filter->setLimitCount(50);
        $filter->setSortViews(true);
        $filter->setGlobalSearch(true);
        $filter->setSearchFavorites(true);
        $filter->setSortKey(AccountSearchConstants::SORT_CLIENT);
        $filter->setSortOrder(AccountSearchConstants::SORT_DIR_DESC);

        return $filter;
    }

    protected function tearDown(): void
    {
        // The row count is static, so leaving it set would follow the next test.
        AccountSearchFilterDto::$queryNumRows = null;

        parent::tearDown();
    }
}
