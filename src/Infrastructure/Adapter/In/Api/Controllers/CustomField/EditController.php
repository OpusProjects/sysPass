<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\CustomField\Models\CustomFieldDefinition;

use function SP\__;
use function SP\__u;

final class EditController extends CustomFieldBase
{
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CUSTOMFIELD_EDIT);

        $fieldData = new CustomFieldDefinition([
            'id'          => $this->apiService->getParamInt('id', true),
            'name'        => $this->apiService->getParamString('name', true),
            'typeId'      => $this->apiService->getParamInt('typeId', true),
            'moduleId'    => $this->apiService->getParamInt('moduleId', true),
            'required'    => (int) $this->apiService->getParamInt('required'),
            'help'        => $this->apiService->getParamString('help'),
            'showInList'  => (int) $this->apiService->getParamInt('showInList'),
            'isEncrypted' => (int) $this->apiService->getParamInt('isEncrypted'),
        ]);

        $this->customFieldService->update($fieldData);

        $this->eventDispatcher->notify(new Event(
            'edit.customField',
            $this,
            EventMessage::build()
                ->addDescription(__u('Custom field updated'))
                ->addDetail(__u('Name'), $fieldData->getName())
                ->addDetail('ID', $fieldData->getId())
        ));

        return ApiResponse::makeSuccess($fieldData, __('Custom field updated'), $fieldData->getId());
    }
}
