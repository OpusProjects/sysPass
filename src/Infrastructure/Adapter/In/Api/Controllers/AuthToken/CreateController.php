<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
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

        $tokenData = new AuthToken([
            'userId'   => $this->apiService->getParamInt('userId', true),
            'actionId' => $this->apiService->getParamInt('actionId', true),
            'hash'     => $this->apiService->getParamRaw('password'),
        ]);

        $id = $this->authTokenService->create($tokenData);
        $tokenData = $tokenData->mutate(['id' => $id]);

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
