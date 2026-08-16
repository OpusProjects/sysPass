<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\UserProfile;

use function SP\__;
use function SP\__u;

final class EditController extends ProfileBase
{
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PROFILE_EDIT);

        $profileData = new UserProfile([
            'id'      => $this->apiService->getParamInt('id', true),
            'name'    => $this->apiService->getParamString('name', true),
            // Required: the permissions are the profile. UserProfile.profile is NOT NULL, so
            // leaving it optional meant a caller who omitted it got the database's integrity
            // error rather than being told which parameter was missing.
            'profile' => $this->apiService->getParamString('profile', true),
        ]);

        // The permissions a caller may hand out are bounded by the ones they hold, exactly as they
        // are on the web form. Without this an ordinary user holding a profile token could write a
        // profile granting anything — mgmUsers, mgmAccounts — and "may manage profiles" became
        // "may become an administrator" in two steps.
        $profileData = $profileData->dehydrate($this->constrainToOwnPermissions($profileData));

        $this->profileService->update($profileData);

        $this->eventDispatcher->notify(new Event(
            'edit.profile',
            $this,
            EventMessage::build()
                ->addDescription(__u('Profile updated'))
                ->addDetail(__u('Name'), $profileData->getName())
                ->addDetail('ID', $profileData->getId())
        ));

        return ApiResponse::makeSuccess($profileData, __('Profile updated'), $profileData->getId());
    }
}
