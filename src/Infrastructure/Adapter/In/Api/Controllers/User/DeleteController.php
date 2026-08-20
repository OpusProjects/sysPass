<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__;
use function SP\__u;

final class DeleteController extends UserBase
{
    public function deleteAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_DELETE);

        $id = $this->apiService->getParamInt('id', true);

        $this->denyOnDemoUser($id);

        // The same guard the web form applies, written out because the API surface has no form:
        // an account cannot delete itself, or the token's owner would be removing the identity the
        // request is being made as — and the last administrator could lock the installation.
        if ($id === $this->context->getUserData()->id) {
            throw ValidationException::error(__u('Unable to delete, user in use'));
        }

        $userData = $this->userService->getById($id);
        $this->userService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.user',
            $this,
            EventMessage::build()
                ->addDescription(__u('User removed'))
                ->addDetail(__u('Name'), $userData->getName())
                ->addDetail('ID', $id)
        ));

        return ApiResponse::makeSuccess(self::withoutCredentials($userData), __('User removed'), $id);
    }
}
