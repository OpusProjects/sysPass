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

namespace SP\Tests\Unit\Domain\Core\Dtos;

use Aura\SqlQuery\QueryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Core\Dtos\ItemSearchDto;

/**
 * How far into a list to start, and how much of it to take, both come from the query string.
 *
 * `analyzeInt()` reads a negative as a negative — `Filter::getInt('-1')` is `-1` — and nothing
 * between the request and the query narrowed it, so `?count=-1` was built into `LIMIT -1` and
 * `?start=-5` into `OFFSET -5`. That is not SQL:
 *
 * ```
 * ERROR 1064 (42000): You have an error in your SQL syntax ... near '-1'
 * ```
 *
 * so asking for a page came back as a database failure. Nothing was harmed by it and nothing was
 * disclosed, but a value an ordinary request can carry should not end up as a syntax error.
 *
 * There are two ways in, which is why there are two places to clamp: the item grids and the API
 * searches build an `ItemSearchDto`, while the account search reads `start` and `rpp` straight
 * from the request into `AccountSearchFilterDto`.
 */
#[Group('unitary')]
class PaginationIsNotNegativeTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function negativeProvider(): array
    {
        return [
            'minus one' => [-1, 0],
            'far negative' => [-999999, 0],
            'zero stays zero' => [0, 0],
            'a real page size is untouched' => [50, 50],
        ];
    }

    #[Test]
    #[DataProvider('negativeProvider')]
    public function anItemSearchNeverAsksForANegativePage(int $given, int $expected): void
    {
        $dto = new ItemSearchDto('', $given, $given);

        self::assertSame($expected, $dto->getLimitStart());
        self::assertSame($expected, $dto->getLimitCount());
    }

    #[Test]
    #[DataProvider('negativeProvider')]
    public function anAccountSearchNeverAsksForANegativePage(int $given, int $expected): void
    {
        $filter = AccountSearchFilterDto::build('')
                                        ->setLimitStart($given)
                                        ->setLimitCount($given);

        self::assertSame($expected, $filter->getLimitStart());
        self::assertSame($expected, $filter->getLimitCount());
    }

    /**
     * The statement the server would be sent is a statement it can parse.
     *
     * Asserted on the SQL rather than on the getters, because the getters were never the problem:
     * what went wrong was the string that reached MariaDB.
     */
    #[Test]
    public function theQueryBuiltFromThoseValuesIsValidSql(): void
    {
        $dto = new ItemSearchDto('', -5, -1);

        $statement = (new QueryFactory('mysql'))
            ->newSelect()
            ->cols(['id'])
            ->from('User')
            ->limit($dto->getLimitCount())
            ->offset($dto->getLimitStart())
            ->getStatement();

        self::assertDoesNotMatchRegularExpression(
            '/LIMIT\s+-|OFFSET\s+-/',
            $statement,
            'a negative LIMIT or OFFSET is a syntax error, not an empty page'
        );
    }
}
