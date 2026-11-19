<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
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

        $this->eventDispatcher->notify(new Event(
            'delete.authToken',
            $this,
            EventMessage::build()
                ->addDescription(__u('Authorization removed'))
                ->addDetail('ID', $id)
        ));

        // Without the secrets, as a listing returns it. The row was handed back whole — the
        // token, its hash and its vault — to a caller who needs only AUTHTOKEN_DELETE, not
        // AUTHTOKEN_VIEW. And a user's tokens share one token string across their actions, so the
        // one in a deleted row is still a working credential for every other token that user has.
        return ApiResponse::makeSuccess(self::withoutSecrets($tokenData), __('Authorization removed'), $id);
    }
}
