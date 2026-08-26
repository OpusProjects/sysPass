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

namespace SP\Application\User\Services;

use SP\Application\Application;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Common\Services\Service;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Domain\User\Ports\UserProfileRepository;
use SP\Application\User\Ports\UserProfileService;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;

use function SP\__;
use function SP\__u;

/**
 * Class UserProfile
 */
final class UserProfile extends Service implements UserProfileService
{

    /**
     * @param UserProfileRepository<UserProfileModel> $userProfileRepository
     */
    public function __construct(Application $application, private readonly UserProfileRepository $userProfileRepository)
    {
        parent::__construct($application);
    }

    /**
     * @inheritDoc
     */
    public function assertAssignableBy(int $profileId): void
    {
        if ($this->context->getUserData()->isAdminApp) {
            return;
        }

        try {
            $profileData = $this->getById($profileId)->hydrate(ProfileData::class) ?? new ProfileData();
        } catch (NoSuchItemException) {
            // Nothing to constrain: a profile that does not exist grants nothing. Refusing here
            // would change what a bad id reports — the foreign key already rejects it, and this
            // guard is about how much a profile grants, not whether it is there.
            return;
        }

        if ($profileData->grantsBeyond($this->context->getUserProfile())) {
            throw ServiceException::error(
                __u('You cannot assign a profile with more permissions than your own'),
                __u('Please contact to the administrator')
            );
        }
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function getById(int $id): UserProfileModel
    {
        $result = $this->userProfileRepository->getById($id);

        if ($result->getNumRows() === 0) {
            throw NoSuchItemException::info(__u('Profile not found'));
        }

        return $result->getData(UserProfileModel::class);
    }

    /**
     * @param ItemSearchDto $itemSearchData
     * @return QueryResult<UserProfileModel>
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult
    {
        return $this->userProfileRepository->search($itemSearchData);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function delete(int $id): void
    {
        $this->assertIsNotADirectoryDefault($id);

        if ($this->userProfileRepository->delete($id)->getAffectedNumRows() === 0) {
            throw NoSuchItemException::info(__u('Profile not found'));
        }
    }

    /**
     * Refuse a profile that new directory users are created with.
     *
     * A profile set as the directory's default is held by the configuration rather than by a row,
     * so no foreign key refuses it — `ldapDefaultProfile` and `ssoDefaultProfile` are plain ints in
     * config.xml with nothing pointing at UserProfile. The RESTRICT on User.userProfileId only
     * catches a profile somebody currently holds, and the profile most likely to be deleted while
     * reorganising is one nobody holds any more. Deleting it succeeded cleanly, and then every
     * auto-provisioned login afterwards died on the NOT NULL foreign key in
     * User::createOnLogin(), surfacing as "Internal error, check the event log".
     *
     * @throws ServiceException
     */
    private function assertIsNotADirectoryDefault(int $id): void
    {
        $configData = $this->config->getConfigData();

        $defaults = [
            __('LDAP') => $configData->getLdapDefaultProfile(),
            __('SSO') => $configData->getSsoDefaultProfile(),
        ];

        foreach ($defaults as $label => $default) {
            if ($default === $id) {
                throw ServiceException::warning(
                    __u('Profile in use'),
                    sprintf(__('It is the default profile for %s users'), $label)
                );
            }
        }
    }

    /**
     * @param int[] $ids
     *
     * @throws ServiceException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $ids): int
    {
        // As above: the batch does not pass through delete(), and no foreign key knows about
        // `ldapDefaultProfile`.
        foreach ($ids as $id) {
            $this->assertIsNotADirectoryDefault($id);
        }

        $count = $this->userProfileRepository->deleteByIdBatch($ids)->getAffectedNumRows();

        if ($count !== count($ids)) {
            throw ServiceException::warning(__u('Error while removing the profiles'));
        }

        return $count;
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     */
    public function create(UserProfileModel $userProfile): int
    {
        return $this->userProfileRepository->create($userProfile)->getLastId();
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws DuplicatedItemException
     * @throws ServiceException
     */
    public function update(UserProfileModel $userProfile): void
    {
        if ($this->userProfileRepository->update($userProfile) === 0) {
            throw ServiceException::error(__u('Error while updating the profile'));
        }
    }

    /**
     * @param int $id
     * @return Simple[]
     */
    public function getUsersForProfile(int $id): array
    {
        return $this->userProfileRepository
            ->getAny(
                ['id', 'login'],
                UserModel::TABLE,
                'userProfileId = :userProfileId',
                ['userProfileId' => $id]
            )
            ->getDataAsArray();
    }

    /**
     * Get all items from the service's repository
     *
     * @return array<UserProfileModel>
     */
    public function getAll(): array
    {
        return $this->userProfileRepository->getAll()->getDataAsArray(UserProfileModel::class);
    }
}
