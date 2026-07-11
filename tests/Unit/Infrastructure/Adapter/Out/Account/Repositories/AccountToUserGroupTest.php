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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out\Account\Repositories;

use Aura\SqlQuery\QueryFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Common\Models\Item;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Infrastructure\Adapter\Out\Account\Repositories\AccountToUserGroup;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountToUserGroupRepositoryTest
 *
 */
#[Group('unitary')]
class AccountToUserGroupTest extends UnitaryTestCase
{
    private MockObject|DatabaseInterface $database;
    private AccountToUserGroup $accountToUserGroup;

    /**
     * @throws QueryException
     * @throws ConstraintException
     */
    public function testDeleteTypeByAccountId(): void
    {
        $accountId = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($accountId) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return $params['accountId'] === $accountId
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback)
            ->willReturn(new QueryResult(null, 1));

        $this->accountToUserGroup->deleteByAccountId($accountId);
    }

    public function testGetUserGroupsByAccountId(): void
    {
        $id = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($id) {
                $query = $arg->getQuery();

                return $query->getBindValues()['accountId'] === $id
                       && $arg->getMapClassName() === Item::class
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback)
            ->willReturn(new QueryResult());

        $this->accountToUserGroup->getUserGroupsByAccountId($id);
    }

    /**
     * A group can be linked to any number of accounts (or none), so any affected row count
     * (including zero, when the group has no linked accounts) is a successful outcome.
     *
     * @throws QueryException
     * @throws ConstraintException
     */
    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    public function testDeleteByUserGroupId(int $affectedRows): void
    {
        $userGroupId = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($userGroupId) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return $params['userGroupId'] === $userGroupId
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback)
            ->willReturn(new QueryResult(null, $affectedRows));

        $this->accountToUserGroup->deleteByUserGroupId($userGroupId);
    }

    /**
     * @throws QueryException
     * @throws ConstraintException
     */
    public function testAddByType(): void
    {
        $userGroups = self::getRandomNumbers(10);
        $accountId = self::$faker->randomNumber();
        $isEdit = self::$faker->boolean();

        $callback = new Callback(
            static function (QueryData $arg) use ($userGroups, $accountId, $isEdit) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();
                $statement = $query->getStatement();

                foreach (array_values($userGroups) as $i => $userGroup) {
                    if ($params['accountId_' . $i] !== $accountId
                        || $params['userGroupId_' . $i] !== $userGroup
                        || !str_contains(
                            $statement,
                            sprintf('(:accountId_%1$d, :userGroupId_%1$d, :isEdit)', $i)
                        )
                    ) {
                        return false;
                    }
                }

                return $params['isEdit'] === (int)$isEdit
                       && count($params) === count($userGroups) * 2 + 1
                       && str_contains($statement, 'ON DUPLICATE KEY UPDATE isEdit = :isEdit');
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback);

        $this->accountToUserGroup->addByType($accountId, $userGroups, $isEdit);
    }

    /**
     * An account can have any number of secondary groups (or none), so any affected row count
     * (including zero, or more than one) is a successful outcome, not just exactly one.
     *
     * @throws QueryException
     * @throws ConstraintException
     */
    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    public function testDeleteByAccountId(int $affectedRows): void
    {
        $accountId = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($accountId) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return $params['accountId'] === $accountId
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback)
            ->willReturn(new QueryResult(null, $affectedRows));

        $this->accountToUserGroup->deleteByAccountId($accountId);
    }

    public function testGetUserGroupsByUserGroupId(): void
    {
        $id = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($id) {
                $query = $arg->getQuery();

                return $query->getBindValues()['userGroupId'] === $id
                       && $arg->getMapClassName() === Item::class
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback)
            ->willReturn(new QueryResult());

        $this->accountToUserGroup->getUserGroupsByUserGroupId($id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(DatabaseInterface::class);
        $queryFactory = new QueryFactory('mysql');

        $this->accountToUserGroup = new AccountToUserGroup(
            $this->database,
            $this->context,
            $this->application->getEventDispatcher(),
            $queryFactory,
        );
    }
}
