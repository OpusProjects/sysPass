<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
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
            'profile' => $this->apiService->getParamString('profile'),
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
