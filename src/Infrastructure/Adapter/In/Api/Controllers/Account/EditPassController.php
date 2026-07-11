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

use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Account\Dtos\AccountUpdateDto;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;

use function SP\__;
use function SP\__u;

/**
 * Class EditPassController
 */
final class EditPassController extends AccountBase
{
    /**
     * viewPassAction
     */
    public function editPassAction(): ApiResponse
    {
        $this->setupApi(AclActionsInterface::ACCOUNT_EDIT_PASS);

        $accountUpdateDto = $this->buildAccountUpdateDto();

        $accountUpdateDto = $this->accountPresetService->checkPasswordPreset($accountUpdateDto);

        $this->accountService->editPassword($accountUpdateDto->id, $accountUpdateDto);

        $accountDetails = $this->accountService->getByIdEnriched($accountUpdateDto->id);

        $this->eventDispatcher->notify(new Event(
            'edit.account.pass',
            $this,
            EventMessage::build()
                    ->addDescription(__u('Password updated'))
                    ->addDetail(__u('Name'), $accountDetails->getName())
                    ->addDetail(__u('Client'), $accountDetails->getClientName())
                    ->addDetail('ID', $accountDetails->getId())
        ));

        return ApiResponse::makeSuccess($accountDetails, __('Password updated'), $accountUpdateDto->id);
    }

    /**
     * @throws ServiceException
     */
    private function buildAccountUpdateDto(): AccountUpdateDto
    {
        return new AccountUpdateDto(
            id: $this->apiService->getParamInt('id', true),
            userEditId: $this->context->getUserData()->id,
            passDateChange: $this->apiService->getParamInt('expireDate'),
            pass: $this->apiService->getParamRaw('pass', true),
        );
    }
}
