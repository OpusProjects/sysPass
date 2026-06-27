<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends ProfileBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PROFILE_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $profileData = $this->profileService->getById($id);
        $this->profileService->delete($id);

        $this->eventDispatcher->notify(new Event('delete.profile',
            $this,
            EventMessage::build()
                ->addDescription(__u('Profile removed'))
                ->addDetail(__u('Name'), $profileData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($profileData, __('Profile removed'), $id);
    }
}
