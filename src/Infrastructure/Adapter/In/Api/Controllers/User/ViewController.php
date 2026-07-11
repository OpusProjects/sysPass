<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends UserBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $userData = $this->userService->getById($id);

        $this->eventDispatcher->notify(new Event(
            'show.user',
            $this,
            EventMessage::build()
                ->addDescription(__u('User displayed'))
                ->addDetail(__u('Name'), $userData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($userData);
    }
}
