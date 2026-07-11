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
            'profile' => $this->apiService->getParamString('profile'),
        ]);

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
