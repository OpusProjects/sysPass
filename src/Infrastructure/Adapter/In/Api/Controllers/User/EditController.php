<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Domain\Http\Code;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\User;

use function SP\__;
use function SP\__u;

final class EditController extends UserBase
{
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::USER_EDIT);

        $userData = $this->buildUserData();

        $this->denyOnDemoUser($userData->getId());
        $this->userService->update($userData);

        $this->eventDispatcher->notify(new Event(
            'edit.user',
            $this,
            EventMessage::build()
                ->addDescription(__u('User updated'))
                ->addDetail(__u('Name'), $userData->getName())
                ->addDetail('ID', $userData->getId())
        ));

        return ApiResponse::makeSuccess(self::withoutCredentials($userData), __('User updated'), $userData->getId());
    }

    private function buildUserData(): User
    {
        $userProfileId = $this->apiService->getParamInt('userProfileId', true);

        $this->assertProfileIsAssignable($userProfileId);

        $id = $this->apiService->getParamInt('id', true);

        // A flag the caller does not send keeps its stored value. It used to be written as false,
        // so an administrator renaming another administrator demoted them, and re-enabled them if
        // they had been disabled, without either flag appearing in the request.
        $stored = $this->userService->getById((int)$id);
        $isAdmin = (bool)$this->context->getUserData()->isAdminApp;

        // Each read by name, which is also how HelpMatchesParametersTest finds what this reads.
        $sent = [
            'isAdminApp' => $this->apiService->getParamInt('isAdminApp'),
            'isAdminAcc' => $this->apiService->getParamInt('isAdminAcc'),
            'isDisabled' => $this->apiService->getParamInt('isDisabled'),
            'isChangePass' => $this->apiService->getParamInt('isChangePass'),
            'isTotpRequired' => $this->apiService->getParamInt('isTotpRequired'),
        ];

        $flag = static fn(?int $value, ?bool $current): bool => $value === null ? (bool)$current : (bool)$value;

        // Omitted, the stored email stays (a null column is not written). Sent empty, it would be
        // cleared — which the web form refuses for any user not taken from LDAP.
        $email = $this->apiService->getParamString('email');

        if ($email === '' && !$stored->isLdap()) {
            throw ValidationException::error(__u('An email is needed'), null, Code::BAD_REQUEST->value);
        }

        return new User([
            'id'            => $id,
            'name'          => $this->apiService->getParamString('name', true),
            'login'         => $this->apiService->getParamString('login', true),
            'email'         => $email,
            'notes'         => $this->apiService->getParamString('notes'),
            'userGroupId'   => $this->apiService->getParamInt('userGroupId', true),
            'userProfileId' => $userProfileId,
            // Only an administrator may set either administrator flag.
            'isAdminApp'    => $isAdmin && $flag($sent['isAdminApp'], $stored->isAdminApp()),
            'isAdminAcc'    => $isAdmin && $flag($sent['isAdminAcc'], $stored->isAdminAcc()),
            'isDisabled'    => $flag($sent['isDisabled'], $stored->isDisabled()),
            'isChangePass'  => $flag($sent['isChangePass'], $stored->isChangePass()),
            'isTotpRequired' => $flag($sent['isTotpRequired'], $stored->isTotpRequired()),
        ]);
    }
}
