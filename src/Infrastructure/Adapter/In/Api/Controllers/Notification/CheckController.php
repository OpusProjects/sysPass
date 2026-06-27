<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class CheckController extends NotificationBase
{
    public function checkAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::NOTIFICATION_CHECK);

        $id = $this->apiService->getParamInt('id', true);
        $this->notificationService->setCheckedById($id);

        $this->eventDispatcher->notify(new Event('check.notification',
            $this,
            EventMessage::build()
                ->addDescription(__u('Notification marked as read'))
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess(null, __('Notification marked as read'), $id);
    }
}
