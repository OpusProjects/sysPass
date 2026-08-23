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

namespace SP\Tests\Unit\Infrastructure\Adapter\Out\ItemPreset\Repositories;

use Aura\SqlQuery\Common\DeleteInterface;
use Aura\SqlQuery\Common\InsertInterface;
use Aura\SqlQuery\Common\SelectInterface;
use Aura\SqlQuery\Common\UpdateInterface;
use Aura\SqlQuery\QueryFactory;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Database\Ports\DatabaseInterface;
use SP\Domain\ItemPreset\Models\ItemPreset as ItemPresetModel;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Adapter\Out\ItemPreset\Repositories\ItemPreset;
use SP\Tests\Support\Generators\ItemPresetDataGenerator;
use SP\Tests\Support\UnitaryTestCase;
use stdClass;

/**
 * Class ItemPresetTest
 *
 */
#[Group('unitary')]
class ItemPresetTest extends UnitaryTestCase
{
    private DatabaseInterface|MockObject $database;


    private ItemPreset $itemPreset;

    /**
     * The preset that applies is decided, not left to the database.
     *
     * `score` is `priority + 3 / + 2 / + 1` by how specifically a preset matches, and two presets
     * tie whenever they are equally specific and equally prioritised — a user in two groups, each
     * carrying a preset of the same type at the same priority, scores both at `priority + 2`, which
     * is an ordinary configuration rather than a contrived one. `ORDER BY score DESC LIMIT 1` over
     * a tie lets the database return either, so which password policy or which default permissions
     * that user got was not decided anywhere.
     *
     * Asserted on the statement rather than by setting the tie up against a real database, for the
     * same reason the paged searches are (#836): asked of a tie this MariaDB does in fact return
     * the lower id, so a behavioural test passes whether or not the ordering says so and proves
     * nothing about it. `AccountPresetApplicationTest` builds the tie for real and pins that the
     * answer is stable; this pins that the query asks for a stable one.
     */
    public function testTheOrderThatChoosesAPresetIsTotal(): void
    {
        $statement = null;

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with(
                new Callback(static function (QueryData $arg) use (&$statement) {
                    $statement = $arg->getQuery()->getStatement();

                    return true;
                }),
                false
            );

        $this->itemPreset->getByFilter('test', 100, 200, 300);

        self::assertIsString($statement);
        self::assertSame(
            1,
            preg_match('/ORDER BY(.*?)(?:\\bLIMIT\\b|$)/is', $statement, $matches),
            'the query must carry an ORDER BY'
        );

        $columns = explode(',', trim($matches[1]));
        $last = trim((string)end($columns));

        self::assertSame(
            'id ASC',
            $last,
            'a LIMIT 1 over a score that can tie must break the tie on something unique'
        );
    }


    public function testGetByFilter()
    {
        $item = new ItemSearchDto(self::$faker->name());

        $callback = new Callback(
            static function (QueryData $arg) use ($item) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return count($params) === 5
                       && $params['type'] === 'test'
                       && $params['userId'] === 100
                       && $params['userId2'] === 100
                       && $params['userGroupId'] === 200
                       && $params['userProfileId'] === 300
                       && $arg->getMapClassName() === ItemPresetModel::class
                       && is_a($query, SelectInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback, false);

        $this->itemPreset->getByFilter('test', 100, 200, 300);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testDelete()
    {
        $id = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($id) {
                $query = $arg->getQuery();

                return $query->getBindValues()['id'] === $id
                       && is_a($query, DeleteInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database->expects(self::once())->method('runQuery')->with($callback);

        $this->itemPreset->delete($id);
    }

    public function testGetAll()
    {
        $callback = new Callback(
            static function (QueryData $arg) {
                $query = $arg->getQuery();
                return $arg->getMapClassName() === ItemPresetModel::class
                       && is_a($query, SelectInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback);

        $this->itemPreset->getAll();
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testCreate()
    {
        $itemPreset = ItemPresetDataGenerator::factory()->buildItemPresetData(new stdClass());

        $callbackCreate = new Callback(
            static function (QueryData $arg) use ($itemPreset) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return count($params) === 8
                       && $params['type'] === $itemPreset->getType()
                       && $params['userId'] === $itemPreset->getUserId()
                       && $params['userProfileId'] === $itemPreset->getUserProfileId()
                       && $params['userGroupId'] === $itemPreset->getUserGroupId()
                       && $params['data'] === $itemPreset->getData()
                       && $params['fixed'] === $itemPreset->getFixed()
                       && $params['priority'] === $itemPreset->getPriority()
                       && !empty($params['hash'])
                       && is_a($query, InsertInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::exactly(1))
            ->method('runQuery')
            ->with($callbackCreate)
            ->willReturn(new QueryResult([]));

        $this->itemPreset->create($itemPreset);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testUpdate()
    {
        $itemPreset = ItemPresetDataGenerator::factory()->buildItemPresetData(new stdClass());

        $callbackCreate = new Callback(
            static function (QueryData $arg) use ($itemPreset) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return count($params) === 9
                       && $params['id'] === $itemPreset->getId()
                       && $params['type'] === $itemPreset->getType()
                       && $params['userId'] === $itemPreset->getUserId()
                       && $params['userProfileId'] === $itemPreset->getUserProfileId()
                       && $params['userGroupId'] === $itemPreset->getUserGroupId()
                       && $params['data'] === $itemPreset->getData()
                       && $params['fixed'] === $itemPreset->getFixed()
                       && $params['priority'] === $itemPreset->getPriority()
                       && !empty($params['hash'])
                       && is_a($query, UpdateInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::exactly(1))
            ->method('runQuery')
            ->with($callbackCreate)
            ->willReturn(new QueryResult(null, 1));

        $out = $this->itemPreset->update($itemPreset);

        $this->assertEquals(1, $out);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testSearch()
    {
        $item = new ItemSearchDto(self::$faker->name());

        $callback = new Callback(
            static function (QueryData $arg) use ($item) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();
                $searchStringLike = '%' . $item->getSearchString() . '%';

                return count($params) === 4
                       && $params['type'] === $searchStringLike
                       && $params['userName'] === $searchStringLike
                       && $params['userProfileName'] === $searchStringLike
                       && $params['userGroupName'] === $searchStringLike
                       && $arg->getMapClassName() === ItemPresetModel::class
                       && is_a($query, SelectInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback, true);

        $this->itemPreset->search($item);
    }

    /**
     * @throws Exception
     */
    public function testSearchWithoutString(): void
    {
        $callback = new Callback(
            static function (QueryData $arg) {
                $query = $arg->getQuery();
                return count($query->getBindValues()) === 0
                       && $arg->getMapClassName() === ItemPresetModel::class
                       && is_a($query, SelectInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback, true);

        $this->itemPreset->search(new ItemSearchDto());
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testDeleteByIdBatch()
    {
        $ids = [self::$faker->randomNumber(), self::$faker->randomNumber(), self::$faker->randomNumber()];

        $callback = new Callback(
            static function (QueryData $arg) use ($ids) {
                $query = $arg->getQuery();
                $values = $query->getBindValues();

                return count($values) === 3
                       && array_shift($values) === array_shift($ids)
                       && array_shift($values) === array_shift($ids)
                       && array_shift($values) === array_shift($ids)
                       && $arg->getMapClassName() === Simple::class
                       && is_a($query, DeleteInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback);

        $this->itemPreset->deleteByIdBatch($ids);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testDeleteByIdBatchWithNoIds(): void
    {
        $this->database
            ->expects(self::never())
            ->method('runQuery');

        $this->itemPreset->deleteByIdBatch([]);
    }

    public function testGetById()
    {
        $id = self::$faker->randomNumber();

        $callback = new Callback(
            static function (QueryData $arg) use ($id) {
                $query = $arg->getQuery();
                $params = $query->getBindValues();

                return count($params) === 1
                       && $params['id'] === $id
                       && $arg->getMapClassName() === ItemPresetModel::class
                       && is_a($query, SelectInterface::class)
                       && !empty($query->getStatement());
            }
        );

        $this->database
            ->expects(self::once())
            ->method('runQuery')
            ->with($callback);

        $this->itemPreset->getById($id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(DatabaseInterface::class);
        $queryFactory = new QueryFactory('mysql');

        $this->itemPreset = new ItemPreset(
            $this->database,
            $this->context,
            $this->application->getEventDispatcher(),
            $queryFactory,
        );
    }


}
