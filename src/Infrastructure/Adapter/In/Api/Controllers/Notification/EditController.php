<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Notification\Models\Notification;

use function SP\__;
use function SP\__u;

final class EditController extends NotificationBase
{
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::NOTIFICATION_EDIT);

        $notification = new Notification([
            'id'          => $this->apiService->getParamInt('id', true),
            'type'        => $this->apiService->getParamString('type', true),
            'component'   => $this->apiService->getParamString('component', true),
            'description' => $this->apiService->getParamString('description', true),
            'userId'      => $this->apiService->getParamInt('userId'),
            'sticky'      => (bool) $this->apiService->getParamInt('sticky'),
            'onlyAdmin'   => (bool) $this->apiService->getParamInt('onlyAdmin'),
        ]);

        $this->notificationService->update($notification);

        $this->eventDispatcher->notify(new Event(
            'edit.notification',
            $this,
            EventMessage::build()
                ->addDescription(__u('Notification updated'))
                ->addDetail('ID', $notification->getId())
        ));

        return ApiResponse::makeSuccess($notification, __('Notification updated'), $notification->getId());
    }
}
