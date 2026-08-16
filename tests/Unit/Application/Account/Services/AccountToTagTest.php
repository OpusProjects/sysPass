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

namespace SP\Tests\Unit\Application\Account\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Account\Ports\AccountToTagRepository;
use SP\Application\Account\Services\AccountToTag;
use SP\Domain\Common\Models\Item;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountToTagServiceTest
 *
 */
#[Group('unitary')]
class AccountToTagTest extends UnitaryTestCase
{

    private AccountToTag                      $accountToTag;
    private AccountToTagRepository|MockObject $accountToTagRepository;

    /**
     * Several accounts cost one query, not one each, and each account gets back its own tags.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testGetTagsByAccountIdsReadsThemAllInOnePass()
    {
        $rows = [
            new Item(['accountId' => 11, 'id' => 1, 'name' => 'alpha']),
            new Item(['accountId' => 11, 'id' => 2, 'name' => 'beta']),
            new Item(['accountId' => 22, 'id' => 3, 'name' => 'gamma']),
        ];

        $this->accountToTagRepository
            ->expects(self::once())
            ->method('getTagsByAccountIds')
            ->with([11, 22, 33])
            ->willReturn(new QueryResult($rows));

        $actual = $this->accountToTag->getTagsByAccountIds([11, 22, 33]);

        self::assertSame(['alpha', 'beta'], array_map(static fn(Item $t) => $t->getName(), $actual[11]));
        self::assertSame(['gamma'], array_map(static fn(Item $t) => $t->getName(), $actual[22]));

        // 33 had none, so it is absent rather than present and empty — the export coalesces.
        self::assertArrayNotHasKey(33, $actual);
    }

    /**
     * An empty page must not reach the database to discover it is empty.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testGetTagsByAccountIdsAsksForNothingWhenGivenNothing()
    {
        $this->accountToTagRepository->expects(self::never())->method('getTagsByAccountIds');

        self::assertSame([], $this->accountToTag->getTagsByAccountIds([]));
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testGetTagsByAccountId()
    {
        $accountId = self::$faker->randomNumber();

        $tag = new Item(['id' => self::$faker->randomNumber(), 'name' => self::$faker->colorName()]);
        $result = new QueryResult([$tag]);

        $this->accountToTagRepository
            ->expects(self::once())
            ->method('getTagsByAccountId')
            ->with($accountId)
            ->willReturn($result);

        $actual = $this->accountToTag->getTagsByAccountId($accountId);

        $this->assertCount(1, $actual);
        $this->assertTrue($actual[0] instanceof Item);
        $this->assertSame($tag, $actual[0]);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function testGetTagsByAccountIdWithNotags()
    {
        $accountId = self::$faker->randomNumber();

        $result = new QueryResult([]);

        $this->accountToTagRepository
            ->expects(self::once())
            ->method('getTagsByAccountId')
            ->with($accountId)
            ->willReturn($result);

        $actual = $this->accountToTag->getTagsByAccountId($accountId);

        $this->assertEmpty($actual);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountToTagRepository = $this->createMock(AccountToTagRepository::class);

        $this->accountToTag =
            new AccountToTag($this->application, $this->accountToTagRepository);
    }
}
