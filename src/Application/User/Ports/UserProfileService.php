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

namespace SP\Application\User\Ports;

use SP\Domain\Common\Models\Simple;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;

/**
 * Class UserProfileService
 *
 * @package SP\Domain\Common\Services\UserProfile
 */
interface UserProfileService
{
    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    /**
     * Refuse a profile that grants more than the signed-in user holds themselves
     *
     * Assigning a user a profile hands them everything on it, so a delegate who may create or edit
     * users must not be able to point one at a profile stronger than their own — otherwise "may
     * manage users" is "may become an administrator" in two steps. Application administrators are
     * not constrained; they hold everything by definition.
     *
     * @param int $profileId
     *
     * @throws ServiceException When the profile grants a permission the caller does not hold
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function assertAssignableBy(int $profileId): void;

    public function getById(int $id): UserProfileModel;

    /**
     * @return QueryResult<UserProfileModel>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult;

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function delete(int $id): void;

    /**
     * @param int[] $ids
     *
     * @throws ServiceException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $ids): int;

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     */
    public function create(UserProfileModel $userProfile): int;

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     * @throws ServiceException
     */
    public function update(UserProfileModel $userProfile): void;

    /**
     * @return Simple[]
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getUsersForProfile(int $id): array;

    /**
     * Get all items from the service's repository
     *
     * @return array<UserProfileModel>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getAll(): array;
}
