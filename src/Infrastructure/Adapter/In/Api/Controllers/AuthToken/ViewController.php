<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends AuthTokenBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::AUTHTOKEN_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $tokenData = $this->authTokenService->getById($id);

        $this->eventDispatcher->notify(new Event('show.authToken',
            $this, EventMessage::build()
                ->addDescription(__u('Authorization displayed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($tokenData);
    }
}
