<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__u;

final class ViewController extends CustomFieldBase
{
    public function viewAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CUSTOMFIELD_VIEW);

        $id = $this->apiService->getParamInt('id', true);
        $fieldData = $this->customFieldService->getById($id);

        $this->eventDispatcher->notify(new Event('show.customField',
            $this, EventMessage::build()
                ->addDescription(__u('Custom field displayed'))
                ->addDetail(__u('Name'), $fieldData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($fieldData);
    }
}
