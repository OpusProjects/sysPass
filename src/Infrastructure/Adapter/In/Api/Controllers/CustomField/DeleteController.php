<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

final class DeleteController extends CustomFieldBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CUSTOMFIELD_DELETE);

        $id = $this->apiService->getParamInt('id', true);
        $fieldData = $this->customFieldService->getById($id);
        $this->customFieldService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.customField',
            $this,
            EventMessage::build()
                ->addDescription(__u('Custom field removed'))
                ->addDetail(__u('Name'), $fieldData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($fieldData, __('Custom field removed'), $id);
    }
}
