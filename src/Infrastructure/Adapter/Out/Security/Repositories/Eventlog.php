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

namespace SP\Infrastructure\Adapter\Out\Security\Repositories;

use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Security\Models\Eventlog as EventlogModel;
use SP\Domain\Security\Ports\EventlogRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Database\QueryResult;

use function SP\__u;

/**
 * Class Eventlog
 *
 * @template T of EventlogModel
 * @implements EventlogRepository<T>
 */
final class Eventlog extends BaseRepository implements EventlogRepository
{
    public const TABLE = 'EventLog';

    /**
     * Clears the event log
     *
     * @return bool the result
     * @throws QueryException
     * @throws ConstraintException
     */
    public function clear(): bool
    {
        $query = $this->queryFactory
            ->newDelete()
            ->from(self::TABLE);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while clearing the event log out'));

        return $this->db->runQuery($queryData)->getAffectedNumRows() > 0;
    }

    /**
     * Searches for items by a given filter
     *
     * @param ItemSearchDto $itemSearchData
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(self::TABLE)
            ->cols(EventlogModel::getCols(['date']))
            ->cols(['FROM_UNIXTIME(date)' => 'date'])
            ->orderBy(['id DESC'])
            ->limit($itemSearchData->getLimitCount())
            ->offset($itemSearchData->getLimitStart());

        if (!empty($itemSearchData->getSearchString())) {
            $query->where('action LIKE :action OR ipAddress LIKE :ipAddress OR description LIKE :description');

            $search = '%' . $itemSearchData->getSearchString() . '%';

            $query->bindValues(['action' => $search, 'ipAddress' => $search, 'description' => $search]);
        }

        $queryData = QueryData::build($query)->setMapClassName(EventlogModel::class);

        return $this->db->runQuery($queryData, true);
    }


    /**
     * @param EventlogModel $eventlog
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function create(EventlogModel $eventlog): QueryResult
    {
        $query = $this->queryFactory
            ->newInsert()
            ->into(self::TABLE)
            ->cols($eventlog->toArray(null, ['id', 'date']))
            ->set('date', 'UNIX_TIMESTAMP()');

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while adding the event log'));

        return $this->db->runQuery($queryData);
    }
}
