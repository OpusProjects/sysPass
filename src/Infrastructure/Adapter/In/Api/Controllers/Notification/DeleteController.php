<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends NotificationBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::NOTIFICATION_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $notification = $this->notificationService->getById($id);
        $this->notificationService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.notification',
            $this,
            EventMessage::build()
                ->addDescription(__u('Notification removed'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($notification, __('Notification removed'), $id);
    }
}
