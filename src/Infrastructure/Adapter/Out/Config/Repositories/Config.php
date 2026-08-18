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

namespace SP\Infrastructure\Adapter\Out\Config\Repositories;

use SP\Domain\Common\Models\Simple;
use SP\Domain\Config\Models\Config as ConfigModel;
use SP\Domain\Config\Ports\ConfigRepository;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;

use function SP\__u;

/**
 * Class Config
 *
 * @template T of ConfigModel
 * @implements ConfigRepository<T>
 */
final class Config extends BaseRepository implements ConfigRepository
{
    public const TABLE = 'Config';

    /**
     * Counts one against a numeric parameter, unless it has already reached the limit.
     *
     * The server does the arithmetic and the comparison together. Read it, compare it in PHP and
     * write back `$value + 1` — which is what counting a failed temporary-password attempt used to
     * do — and requests arriving together all read the same number and all write the same number
     * back, so fifty attempts advance the counter by one. A limit counted that way is not a limit.
     *
     * `COALESCE` because `value` is nullable, and a NULL would make both the sum and the
     * comparison NULL: the parameter would stop counting rather than start at zero.
     *
     * The `+ 0` keeps the comparison numeric whoever asks. `Config.value` is a varchar, so if both
     * sides arrive as strings the server compares them as text and `'10' < '3'` is true — a
     * counter would sail past its limit the moment it reached double figures, which is where a
     * limit of fifty starts to matter. Today the right-hand side is an integer and `Database`
     * binds it `PDO::PARAM_INT`, which settles it on its own; this makes it not depend on that.
     *
     * Written without `CAST(… AS UNSIGNED)` on purpose: Aura quotes whatever follows `AS` in a raw
     * expression, so that becomes ``CAST(… AS `UNSIGNED) + 1` `` and the statement will not parse.
     *
     * @return QueryResult<Simple> with one row affected when the attempt was counted, and none
     *                             when the parameter is missing or already at the limit
     * @throws ConstraintException
     * @throws QueryException
     */
    public function incrementIfBelow(string $param, int $limit): QueryResult
    {
        $query = $this->queryFactory
            ->newUpdate()
            ->table(self::TABLE)
            ->set('value', 'COALESCE(`value`, \'0\') + 1')
            // No LIMIT: `parameter` is the primary key, so at most one row can match anyway.
            ->where('parameter = :parameter')
            ->where('COALESCE(`value`, \'0\') + 0 < :limit')
            ->bindValues(['parameter' => $param, 'limit' => $limit]);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while updating the config parameter'));

        return $this->db->runQuery($queryData);
    }

    /**
     * @param ConfigModel $config
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function update(ConfigModel $config): QueryResult
    {
        $query = $this->queryFactory
            ->newUpdate()
            ->table(self::TABLE)
            ->cols($config->toArray(['value']))
            ->where('parameter = :parameter')
            ->limit(1)
            ->bindValues(
                [
                    'value' => $config->getValue(),
                    'parameter' => $config->getParameter()
                ]
            );

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while updating the config parameter'));

        return $this->db->runQuery($queryData);
    }

    /**
     * @param ConfigModel $config
     *
     * @return QueryResult<Simple>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function create(ConfigModel $config): QueryResult
    {
        $query = $this->queryFactory
            ->newInsert()
            ->into(self::TABLE)
            ->cols($config->toArray());

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while creating the config parameter'));

        return $this->db->runQuery($queryData);
    }

    /**
     * @param string $param
     *
     * @return QueryResult<T>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getByParam(string $param): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(self::TABLE)
            ->cols(ConfigModel::getCols())
            ->where('parameter = :parameter')
            ->bindValues(['parameter' => $param])
            ->limit(1);

        $queryData = QueryData::buildWithMapper($query, ConfigModel::class);

        return $this->db->runQuery($queryData);
    }

    /**
     * @param string $param
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    public function has(string $param): bool
    {
        $query = $this->queryFactory
            ->newSelect()
            ->from(self::TABLE)
            ->cols(ConfigModel::getCols(['value']))
            ->where('parameter = :parameter')
            ->bindValues(['parameter' => $param])
            ->limit(1);

        $queryData = QueryData::build($query);

        return $this->db->runQuery($queryData)->getNumRows() === 1;
    }
}
