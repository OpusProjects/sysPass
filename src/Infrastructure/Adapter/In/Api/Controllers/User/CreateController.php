<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\User;

use function SP\__;
use function SP\__u;

final class CreateController extends UserBase
{
    public function createAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_CREATE);

        $userData = $this->buildUserData();
        $id = $this->userService->create($userData);
        $userData = $userData->mutate(['id' => $id]);

        $this->eventDispatcher->notify(new Event(
            'create.user',
            $this,
            EventMessage::build()
                ->addDescription(__u('User added'))
                ->addDetail(__u('Name'), $userData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess($userData, __('User added'), $id);
    }

    private function buildUserData(): User
    {
        return new User([
            'name'          => $this->apiService->getParamString('name', true),
            'login'         => $this->apiService->getParamString('login', true),
            'email'         => $this->apiService->getParamString('email'),
            'notes'         => $this->apiService->getParamString('notes'),
            'userGroupId'   => $this->apiService->getParamInt('userGroupId', true),
            'userProfileId' => $this->apiService->getParamInt('userProfileId', true),
            'isAdminApp'    => $this->context->getUserData()->isAdminApp && (bool) $this->apiService->getParamInt('isAdminApp'),
            'isAdminAcc'    => $this->context->getUserData()->isAdminApp && (bool) $this->apiService->getParamInt('isAdminAcc'),
            'isDisabled'    => (bool) $this->apiService->getParamInt('isDisabled'),
            'isChangePass'  => (bool) $this->apiService->getParamInt('isChangePass'),
        ]);
    }
}
