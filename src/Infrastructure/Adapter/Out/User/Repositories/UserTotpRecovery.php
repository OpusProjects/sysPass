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

namespace SP\Infrastructure\Adapter\Out\User\Repositories;

use SP\Domain\User\Models\UserTotpRecovery as UserTotpRecoveryModel;
use SP\Domain\User\Ports\UserTotpRecoveryRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;

use function SP\__u;

/**
 * Class UserTotpRecovery
 */
final class UserTotpRecovery extends BaseRepository implements UserTotpRecoveryRepository
{
    /**
     * @inheritDoc
     */
    public function replace(int $userId, array $hashes): void
    {
        // One transaction: a failure part way leaves the codes the user already has, not a few
        // of the new ones that were never shown to them.
        $this->transactionAware(function () use ($userId, $hashes): void {
            $this->deleteByUserId($userId);

            foreach ($hashes as $hash) {
                $query = $this->queryFactory
                    ->newInsert()
                    ->into(UserTotpRecoveryModel::TABLE)
                    ->cols(['userId' => $userId, 'hash' => $hash]);

                $this->db->runQuery(
                    QueryData::build($query)->setOnErrorMessage(__u('Error while saving the recovery codes'))
                );
            }
        }, $this);
    }

    /**
     * @inheritDoc
     */
    public function countUnused(int $userId, ?string $hash = null): int
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(['id'])
            ->from(UserTotpRecoveryModel::TABLE)
            ->where('userId = :userId', ['userId' => $userId])
            ->where('used = 0');

        if ($hash !== null) {
            $query->where('hash = :hash', ['hash' => $hash]);
        }

        return $this->db->runQuery(QueryData::build($query))->getNumRows();
    }

    /**
     * @inheritDoc
     */
    public function consume(int $userId, string $hash): int
    {
        $query = $this->queryFactory
            ->newUpdate()
            ->table(UserTotpRecoveryModel::TABLE)
            ->set('used', '1')
            ->where('userId = :userId', ['userId' => $userId])
            ->where('hash = :hash', ['hash' => $hash])
            ->where('used = 0');

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while checking the code'));

        return $this->db->runQuery($queryData)->getAffectedNumRows();
    }

    /**
     * @inheritDoc
     */
    public function deleteByUserId(int $userId): int
    {
        $query = $this->queryFactory
            ->newDelete()
            ->from(UserTotpRecoveryModel::TABLE)
            ->where('userId = :userId', ['userId' => $userId]);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while removing the recovery codes'));

        return $this->db->runQuery($queryData)->getAffectedNumRows();
    }
}
