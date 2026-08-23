<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Auth\Models\AuthToken;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class EditController extends AuthTokenBase
{
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::AUTHTOKEN_EDIT);

        $actionId = $this->apiService->getParamInt('actionId', true);
        $password = $this->apiService->getParamRaw('password');

        $this->prepareSecureToken($actionId, $password);

        $tokenData = new AuthToken([
            'id'       => $this->apiService->getParamInt('id', true),
            'userId'   => $this->apiService->getParamInt('userId', true),
            'actionId' => $actionId,
            'hash'     => $password,
        ]);

        $this->authTokenService->update($tokenData);

        $this->eventDispatcher->notify(new Event(
            'edit.authToken',
            $this,
            EventMessage::build()
                ->addDescription(__u('Authorization updated'))
                ->addDetail('ID', $tokenData->getId())
        ));

        return ApiResponse::makeSuccess($tokenData, __('Authorization updated'), $tokenData->getId());
    }
}
