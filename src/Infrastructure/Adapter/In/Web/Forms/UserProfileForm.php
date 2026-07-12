<?php
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

namespace SP\Infrastructure\Adapter\In\Web\Forms;

use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\UserProfile;

use function SP\__u;

/**
 * Class UserProfileForm
 *
 * @package SP\Infrastructure\Adapter\In\Web\Forms
 */
final class UserProfileForm extends FormBase implements FormInterface
{
    protected ?UserProfile $userProfileData = null;

    /**
     * Validate the form
     *
     * @param  int  $action
     * @param  int|null  $id
     *
     * @return UserProfileForm|FormInterface
     * @throws ValidationException
     */
    public function validateFor(int $action, ?int $id = null): FormInterface
    {
        if ($id !== null) {
            $this->itemId = $id;
        }

        switch ($action) {
            case AclActionsInterface::PROFILE_CREATE:
            case AclActionsInterface::PROFILE_EDIT:
                $this->analyzeRequestData();
                $this->checkCommon();
                break;
        }

        return $this;
    }

    /**
     * Analyze the HTTP request data
     *
     * @return void
     */
    protected function analyzeRequestData(): void
    {
        $profileData = $this->getProfileDataFromRequest();

        $this->userProfileData = (new UserProfile([
            'id' => $this->itemId,
            'name' => $this->request->analyzeString('profile_name'),
        ]))->dehydrate($profileData);
    }

    private function getProfileDataFromRequest(): ProfileData
    {
        $profileData = new ProfileData([
            'accAdd' => $this->request->analyzeBool('profile_accadd', false),
            'accView' => $this->request->analyzeBool('profile_accview', false),
            'accViewPass' => $this->request->analyzeBool('profile_accviewpass', false),
            'accViewHistory' => $this->request->analyzeBool('profile_accviewhistory', false),
            'accEdit' => $this->request->analyzeBool('profile_accedit', false),
            'accEditPass' => $this->request->analyzeBool('profile_acceditpass', false),
            'accDelete' => $this->request->analyzeBool('profile_accdel', false),
            'accFiles' => $this->request->analyzeBool('profile_accfiles', false),
            'accPublicLinks' => $this->request->analyzeBool('profile_accpublinks', false),
            'accPrivate' => $this->request->analyzeBool('profile_accprivate', false),
            'accPrivateGroup' => $this->request->analyzeBool('profile_accprivategroup', false),
            'accPermission' => $this->request->analyzeBool('profile_accpermissions', false),
            'accGlobalSearch' => $this->request->analyzeBool('profile_accglobalsearch', false),
            'configGeneral' => $this->request->analyzeBool('profile_config', false),
            'configEncryption' => $this->request->analyzeBool('profile_configmpw', false),
            'configBackup' => $this->request->analyzeBool('profile_configback', false),
            'configImport' => $this->request->analyzeBool('profile_configimport', false),
            'mgmCategories' => $this->request->analyzeBool('profile_categories', false),
            'mgmCustomers' => $this->request->analyzeBool('profile_customers', false),
            'mgmCustomFields' => $this->request->analyzeBool('profile_customfields', false),
            'mgmUsers' => $this->request->analyzeBool('profile_users', false),
            'mgmGroups' => $this->request->analyzeBool('profile_groups', false),
            'mgmProfiles' => $this->request->analyzeBool('profile_profiles', false),
            'mgmApiTokens' => $this->request->analyzeBool('profile_apitokens', false),
            'mgmPublicLinks' => $this->request->analyzeBool('profile_publinks', false),
            'mgmAccounts' => $this->request->analyzeBool('profile_accounts', false),
            'mgmFiles' => $this->request->analyzeBool('profile_files', false),
            'mgmItemsPreset' => $this->request->analyzeBool('profile_items_preset', false),
            'mgmTags' => $this->request->analyzeBool('profile_tags', false),
            'evl' => $this->request->analyzeBool('profile_eventlog', false),
        ]);

        if (!$this->context->getUserData()->isAdminApp) {
            $profileData = $this->constrainProfileToActorPermissions($profileData);
        }

        return $profileData;
    }

    /**
     * Intersect every permission bit with the acting user's own profile so that
     * a non-admin delegate can never grant permissions they don't hold themselves.
     */
    private function constrainProfileToActorPermissions(ProfileData $profileData): ProfileData
    {
        $actorProfile = $this->context->getUserProfile();
        $mutations = [];

        foreach ($profileData->toArray() as $prop => $value) {
            if (!is_bool($value)) {
                continue;
            }

            $getter = 'is' . ucfirst($prop);

            if (!method_exists($profileData, $getter)) {
                continue;
            }

            $actorHasBit = $actorProfile !== null && $actorProfile->$getter();
            $mutations[$prop] = $value && $actorHasBit;
        }

        return $profileData->mutate($mutations);
    }

    /**
     * @throws ValidationException
     */
    protected function checkCommon(): void
    {
        if (!$this->userProfileData->getName()) {
            throw new ValidationException(__u('A profile name is needed'));
        }
    }

    /**
     * @throws SPException
     */
    public function getItemData(): UserProfile
    {
        if (null === $this->userProfileData) {
            throw new SPException(__u('Profile data not set'));
        }

        return $this->userProfileData;
    }
}
