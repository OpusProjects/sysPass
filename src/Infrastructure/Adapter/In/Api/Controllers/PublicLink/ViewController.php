<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\PublicLink;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends PublicLinkBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PUBLICLINK_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $linkData = $this->publicLinkService->getById($id);

        $this->eventDispatcher->notify(new Event(
            'show.publicLink',
            $this,
            EventMessage::build()
                ->addDescription(__u('Public link displayed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($linkData);
    }
}
