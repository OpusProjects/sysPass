<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends AuthTokenBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::AUTHTOKEN_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $tokenData = $this->authTokenService->getById($id);
        $this->authTokenService->delete($id);

        $this->eventDispatcher->notify(new Event('delete.authToken',
            $this,
            EventMessage::build()
                ->addDescription(__u('Authorization removed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($tokenData, __('Authorization removed'), $id);
    }
}
