<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends UserBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $userData = $this->userService->getById($id);
        $this->userService->delete($id);

        $this->eventDispatcher->notify(new Event('delete.user',
            $this,
            EventMessage::build()
                ->addDescription(__u('User removed'))
                ->addDetail(__u('Name'), $userData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($userData, __('User removed'), $id);
    }
}
