<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\CustomField;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\CustomField\Models\CustomFieldDefinition;

use function SP\__;
use function SP\__u;

final class CreateController extends CustomFieldBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::CUSTOMFIELD_CREATE);

        $fieldData = new CustomFieldDefinition([
            'name'        => $this->apiService->getParamString('name', true),
            'typeId'      => $this->apiService->getParamInt('typeId', true),
            'moduleId'    => $this->apiService->getParamInt('moduleId', true),
            'required'    => (int) $this->apiService->getParamInt('required'),
            'help'        => $this->apiService->getParamString('help'),
            'showInList'  => (int) $this->apiService->getParamInt('showInList'),
            'isEncrypted' => (int) $this->apiService->getParamInt('isEncrypted'),
        ]);

        $id = $this->customFieldService->create($fieldData);
        $fieldData = $fieldData->mutate(['id' => $id]);

        $this->eventDispatcher->notify(new Event('create.customField',
            $this,
            EventMessage::build()
                ->addDescription(__u('Custom field added'))
                ->addDetail(__u('Name'), $fieldData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($fieldData, __('Custom field added'), $id);
    }
}
