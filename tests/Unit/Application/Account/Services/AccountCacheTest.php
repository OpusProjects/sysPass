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
use SP\Domain\Account\Dtos\AccountCacheDto;
use SP\Domain\Account\Ports\AccountToUserGroupRepository;
use SP\Domain\Account\Ports\AccountToUserRepository;
use SP\Application\Account\Services\AccountCache;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\Out\Account\Repositories\AccountToUserGroup;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountCacheServiceTest
 *
 */
#[Group('unitary')]
class AccountCacheTest extends UnitaryTestCase
{

    private AccountToUserRepository|MockObject $accountToUserRepository;
    private AccountToUserGroup|MockObject      $accountToUserGroupRepository;
    private AccountCache                       $accountCache;

    /**
     * @throws QueryException
     * @throws ConstraintException
     * @throws SPException
     */
    public function testGetCacheForAccount()
    {
        $accountId = self::$faker->randomNumber();
        $dateEdit = self::$faker->unixTime();

        $accountCacheDto = new AccountCacheDto($accountId, [1, 2, 3], [1, 2, 3]);

        $this->accountToUserRepository
            ->expects(self::once())
            ->method('getUsersByAccountId')
            ->with($accountId)
            ->willReturn(new QueryResult([1, 2, 3]));

        $this->accountToUserGroupRepository
            ->expects(self::once())
            ->method('getUserGroupsByAccountId')
            ->with($accountId)
            ->willReturn(new QueryResult([1, 2, 3]));

        $out = $this->accountCache->getCacheForAccount($accountId, $dateEdit);

        $this->assertEquals($accountCacheDto, $out);
    }

    /**
     * A page of accounts costs one query per repository, not one per account.
     *
     * The listing asks the cache for every account it shows, and a miss reads that account's users
     * and groups — so fifty accounts used to be a hundred queries the first time a page was
     * rendered. This loads the page in one pass, the way the tags on the same page already were.
     *
     * @throws QueryException
     * @throws ConstraintException
     * @throws SPException
     */
    public function testWarmUpForLoadsAWholePageInOnePass()
    {
        $accountIds = [11, 22, 33];

        $this->accountToUserRepository
            ->expects(self::once())
            ->method('getUsersByAccountIds')
            ->with($accountIds)
            ->willReturn(new QueryResult([]));

        $this->accountToUserGroupRepository
            ->expects(self::once())
            ->method('getUserGroupsByAccountIds')
            ->with($accountIds)
            ->willReturn(new QueryResult([]));

        $this->accountCache->warmUpFor(array_fill_keys($accountIds, self::$faker->unixTime()));

        // And every account it covered is a hit afterwards, so the row loop reads nothing more.
        $this->accountToUserRepository->expects(self::never())->method('getUsersByAccountId');
        $this->accountToUserGroupRepository->expects(self::never())->method('getUserGroupsByAccountId');

        foreach ($accountIds as $accountId) {
            $this->accountCache->getCacheForAccount($accountId, 0);
        }
    }

    /**
     * Nothing to load is no query at all — a page whose accounts are already cached, or an empty
     * page, must not reach the database to discover that.
     *
     * @throws QueryException
     * @throws ConstraintException
     * @throws SPException
     */
    public function testWarmUpForAsksForNothingWhenThereIsNothingMissing()
    {
        $accountId = self::$faker->randomNumber();

        $this->context->setAccountsCache([$accountId => new AccountCacheDto($accountId, [], [])]);

        $this->accountToUserRepository->expects(self::never())->method('getUsersByAccountIds');
        $this->accountToUserGroupRepository->expects(self::never())->method('getUserGroupsByAccountIds');

        $this->accountCache->warmUpFor([$accountId => 0]);
        $this->accountCache->warmUpFor([]);
    }

    /**
     * @throws QueryException
     * @throws ConstraintException
     * @throws SPException
     */
    public function testGetCacheForAccountWithCacheHit()
    {
        $accountId = self::$faker->randomNumber();

        $accountCacheDto = new AccountCacheDto($accountId, [1, 2, 3], [1, 2, 3]);

        $this->context->setAccountsCache([$accountId => $accountCacheDto]);

        $this->accountToUserRepository
            ->expects(self::never())
            ->method('getUsersByAccountId');

        $this->accountToUserGroupRepository
            ->expects(self::never())
            ->method('getUserGroupsByAccountId');

        $out = $this->accountCache->getCacheForAccount($accountId, $accountCacheDto->getTime());

        $this->assertEquals($accountCacheDto, $out);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountToUserRepository = $this->createMock(AccountToUserRepository::class);
        $this->accountToUserGroupRepository = $this->createMock(AccountToUserGroupRepository::class);

        $this->accountCache = new AccountCache(
            $this->application,
            $this->accountToUserRepository,
            $this->accountToUserGroupRepository
        );
    }

}
