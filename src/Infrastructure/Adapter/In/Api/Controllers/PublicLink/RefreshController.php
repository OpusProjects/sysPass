<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\PublicLink;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class RefreshController extends PublicLinkBase
{
    public function refreshAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PUBLICLINK_REFRESH);

        $id = $this->apiService->getParamInt('id', true);
        $this->publicLinkService->refresh($id);

        $this->eventDispatcher->notify(new Event(
            'refresh.publicLink',
            $this,
            EventMessage::build()
                ->addDescription(__u('Public link refreshed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess(null, __('Public link refreshed'), $id);
    }
}
