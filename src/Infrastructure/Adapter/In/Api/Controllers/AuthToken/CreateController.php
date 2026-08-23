<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Auth\Models\AuthToken;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class CreateController extends AuthTokenBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::AUTHTOKEN_CREATE);

        $actionId = $this->apiService->getParamInt('actionId', true);
        $password = $this->apiService->getParamRaw('password');

        $this->prepareSecureToken($actionId, $password);

        $tokenData = new AuthToken([
            'userId'   => $this->apiService->getParamInt('userId', true),
            'actionId' => $actionId,
            'hash'     => $password,
        ]);

        $id = $this->authTokenService->create($tokenData);

        // The token is generated inside the service and only exists on the stored row, so the
        // request model the caller sent back would carry a null one. Creating a token a caller
        // cannot read is creating nothing: this is the one moment it is theirs to see.
        $tokenData = $this->authTokenService->getById($id);

        $this->eventDispatcher->notify(new Event(
            'create.authToken',
            $this,
            EventMessage::build()
                ->addDescription(__u('Authorization added'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($tokenData, __('Authorization added'), $id);
    }
}
