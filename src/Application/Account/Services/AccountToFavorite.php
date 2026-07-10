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

namespace SP\Application\Account\Services;

use SP\Core\Application;
use SP\Domain\Account\Ports\AccountToFavoriteRepository;
use SP\Application\Account\Ports\AccountToFavoriteService;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Common\Services\Service;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;

/**
 * Class AccountToFavorite
 */
final class AccountToFavorite extends Service implements AccountToFavoriteService
{

    public function __construct(
        Application                                  $application,
        private readonly AccountToFavoriteRepository $accountFavoriteRepository
    ) {
        parent::__construct($application);
    }

    /**
     * Get an array with the IDs of the favorite accounts
     *
     * @return Simple[]
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    public function getForUserId(int $id): array
    {
        return $this->accountFavoriteRepository
            ->getForUserId($id)
            ->getDataAsArray();
    }

    /**
     * Add an account to the favorites list
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function add(int $accountId, int $userId): int
    {
        return $this->accountFavoriteRepository->add($accountId, $userId);
    }

    /**
     * Remove an account from the favorites list
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $accountId, int $userId): bool
    {
        return $this->accountFavoriteRepository->delete($accountId, $userId);
    }
}
