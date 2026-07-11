<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\PublicLink;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends PublicLinkBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::PUBLICLINK_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $linkData = $this->publicLinkService->getById($id);
        $this->publicLinkService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.publicLink',
            $this,
            EventMessage::build()
                ->addDescription(__u('Public link removed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($linkData, __('Public link removed'), $id);
    }
}
