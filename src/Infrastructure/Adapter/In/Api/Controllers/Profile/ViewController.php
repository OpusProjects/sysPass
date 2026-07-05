<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends ProfileBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PROFILE_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $profileData = $this->profileService->getById($id);

        $this->eventDispatcher->notify(new Event(
            'show.profile',
            $this,
            EventMessage::build()
                ->addDescription(__u('Profile displayed'))
                ->addDetail(__u('Name'), $profileData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($profileData);
    }
}
