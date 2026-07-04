<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Account;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Account\Dtos\AccountUpdateDto;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

/**
 * Class EditController
 */
final class EditController extends AccountBase
{
    /**
     * editAction
     */
    public function editAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_EDIT);

        $accountUpdateDto = $this->buildAccountUpdateDto();

        $this->accountService->update($accountUpdateDto->id, $accountUpdateDto);

        $accountDetails = $this->accountService->getByIdEnriched($accountUpdateDto->id);

        $this->eventDispatcher->notify(new Event('edit.account', 
                $this,
                EventMessage::build()
                    ->addDescription(__u('Account updated'))
                    ->addDetail(__u('Name'), $accountDetails->getName())
                    ->addDetail(__u('Client'), $accountDetails->getClientName())
                    ->addDetail('ID', $accountDetails->getId())
            )
        );

        return ApiResponse::makeSuccess($accountDetails, __('Account updated'), $accountUpdateDto->id);
    }

    /**
     * @throws ServiceException
     */
    private function buildAccountUpdateDto(): AccountUpdateDto
    {
        return new AccountUpdateDto(
            id: $this->apiService->getParamInt('id', true),
            clientId: $this->apiService->getParamInt('clientId', true),
            categoryId: $this->apiService->getParamInt('categoryId', true),
            userId: $this->apiService->getParamInt('userId', false),
            userGroupId: $this->apiService->getParamInt('userGroupId', false),
            userEditId: $this->context->getUserData()->id,
            parentId: $this->apiService->getParamInt('parentId'),
            passDateChange: $this->apiService->getParamInt('expireDate'),
            name: $this->apiService->getParamString('name', true),
            login: $this->apiService->getParamString('login'),
            url: $this->apiService->getParamString('url'),
            notes: $this->apiService->getParamString('notes'),
            isPrivate: (bool)$this->apiService->getParamInt('private'),
            isPrivateGroup: (bool)$this->apiService->getParamInt('privateGroup'),
            tags: array_map(intval(...), $this->apiService->getParamArray('tagsId', false, [])),
        );
    }
}
