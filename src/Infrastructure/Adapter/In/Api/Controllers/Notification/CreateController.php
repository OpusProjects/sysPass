<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Notification\Models\Notification;

use function SP\__;
use function SP\__u;

final class CreateController extends NotificationBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::NOTIFICATION_CREATE);

        $notification = new Notification([
            'type'        => $this->apiService->getParamString('type', true),
            'component'   => $this->apiService->getParamString('component', true),
            'description' => $this->apiService->getParamString('description', true),
            'userId'      => $this->apiService->getParamInt('userId'),
            'sticky'      => (bool) $this->apiService->getParamInt('sticky'),
            'onlyAdmin'   => (bool) $this->apiService->getParamInt('onlyAdmin'),
        ]);

        $id = $this->notificationService->create($notification);
        $notification = $notification->mutate(['id' => $id]);

        $this->eventDispatcher->notify(new Event(
            'create.notification',
            $this,
            EventMessage::build()
                ->addDescription(__u('Notification added'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($notification, __('Notification added'), $id);
    }
}
