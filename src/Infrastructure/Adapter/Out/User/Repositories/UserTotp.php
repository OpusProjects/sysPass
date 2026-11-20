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

use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\User\Models\UserTotp as UserTotpModel;
use SP\Domain\User\Ports\UserTotpRepository;
use SP\Infrastructure\Adapter\Out\Common\Repositories\BaseRepository;
use SP\Infrastructure\Database\QueryData;

use function SP\__u;

/**
 * Class UserTotp
 *
 * @template T of UserTotpModel
 * @implements UserTotpRepository<T>
 */
final class UserTotp extends BaseRepository implements UserTotpRepository
{
    /**
     * @inheritDoc
     */
    public function getByUserId(int $userId): QueryResult
    {
        $query = $this->queryFactory
            ->newSelect()
            ->cols(UserTotpModel::getCols())
            ->from(UserTotpModel::TABLE)
            ->where('userId = :userId', ['userId' => $userId])
            ->limit(1);

        return $this->db->runQuery(QueryData::buildWithMapper($query, UserTotpModel::class));
    }

    /**
     * @inheritDoc
     */
    public function replace(UserTotpModel $userTotp): int
    {
        // Delete and insert as one transaction, so a concurrent reader sees the old row or the
        // new one and never neither.
        return $this->transactionAware(function () use ($userTotp): int {
            $this->deleteByUserId((int)$userTotp->getUserId());

            $query = $this->queryFactory
                ->newInsert()
                ->into(UserTotpModel::TABLE)
                ->cols([
                           'userId' => $userTotp->getUserId(),
                           'secret' => $userTotp->getSecret(),
                           'secretKey' => $userTotp->getSecretKey(),
                       ]);

            $queryData = QueryData::build($query)
                                  ->setOnErrorMessage(__u('Error while saving the two-factor secret'));

            return $this->db->runQuery($queryData)->getAffectedNumRows();
        }, $this);
    }

    /**
     * @inheritDoc
     */
    public function enable(int $userId, int $step): int
    {
        $query = $this->queryFactory
            ->newUpdate()
            ->table(UserTotpModel::TABLE)
            ->cols(['lastStep' => $step])
            ->set('enabledAt', 'UNIX_TIMESTAMP()')
            ->where('userId = :userId', ['userId' => $userId])
            ->where('enabledAt IS NULL');

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while saving the two-factor secret'));

        return $this->db->runQuery($queryData)->getAffectedNumRows();
    }

    /**
     * @inheritDoc
     */
    public function consumeStep(int $userId, int $step): int
    {
        $query = $this->queryFactory
            ->newUpdate()
            ->table(UserTotpModel::TABLE)
            ->cols(['lastStep' => $step])
            ->where('userId = :userId', ['userId' => $userId])
            ->where('enabledAt IS NOT NULL')
            ->where('(lastStep IS NULL OR lastStep < :spentStep)', ['spentStep' => $step]);

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
            ->from(UserTotpModel::TABLE)
            ->where('userId = :userId', ['userId' => $userId]);

        $queryData = QueryData::build($query)->setOnErrorMessage(__u('Error while removing the two-factor secret'));

        return $this->db->runQuery($queryData)->getAffectedNumRows();
    }
}
