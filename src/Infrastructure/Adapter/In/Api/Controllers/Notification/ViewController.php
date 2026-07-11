<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends NotificationBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::NOTIFICATION_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $notification = $this->notificationService->getById($id);

        $this->eventDispatcher->notify(new Event(
            'show.notification',
            $this,
            EventMessage::build()
                ->addDescription(__u('Notification displayed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($notification);
    }
}
