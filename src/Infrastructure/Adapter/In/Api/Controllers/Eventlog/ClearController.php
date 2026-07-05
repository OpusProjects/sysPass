<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Eventlog;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class ClearController extends EventlogBase
{
    public function clearAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::EVENTLOG_CLEAR);

        $this->eventlogService->clear();

        $this->eventDispatcher->notify(new Event(
            'clear.eventlog',
            $this,
            EventMessage::build()->addDescription(__u('Event log cleared'))
        ));

        return ApiResponse::makeSuccess(null, __('Event log cleared'));
    }
}
