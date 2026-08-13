<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\UserProfile;

use function SP\__;
use function SP\__u;

final class CreateController extends ProfileBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PROFILE_CREATE);

        $profileData = new UserProfile([
            'name'    => $this->apiService->getParamString('name', true),
            // Required: the permissions are the profile. UserProfile.profile is NOT NULL, so
            // leaving it optional meant a caller who omitted it got the database's integrity
            // error rather than being told which parameter was missing.
            'profile' => $this->apiService->getParamString('profile', true),
        ]);

        $id = $this->profileService->create($profileData);
        $profileData = $profileData->mutate(['id' => $id]);

        $this->eventDispatcher->notify(new Event(
            'create.profile',
            $this,
            EventMessage::build()
                ->addDescription(__u('Profile added'))
                ->addDetail(__u('Name'), $profileData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($profileData, __('Profile added'), $id);
    }
}
