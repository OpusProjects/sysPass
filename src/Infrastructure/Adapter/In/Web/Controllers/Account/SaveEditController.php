<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Account;

use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\CustomField\Models\CustomFieldData as CustomFieldDataModel;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountAclEnforcer;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;

/**
 * Class SaveEditController
 */
final class SaveEditController extends AccountSaveBase
{
    /**
     * @param CustomFieldDataService<CustomFieldDataModel> $customFieldService
     */
    public function __construct(
        Application                        $application,
        WebControllerHelper                $webControllerHelper,
        AccountService                     $accountService,
        AccountPresetService               $accountPresetService,
        CustomFieldDataService             $customFieldService,
        private readonly AccountAclEnforcer $accountAclEnforcer
    ) {
        parent::__construct(
            $application,
            $webControllerHelper,
            $accountService,
            $accountPresetService,
            $customFieldService
        );
    }

    /**
     * Saves edit action
     *
     * @param int $id Account's ID
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function saveEditAction(int $id): ActionResponse
    {
        $this->accountAclEnforcer->checkAccountAccess(AclActionsInterface::ACCOUNT_EDIT, $id);

        $this->accountForm->validateFor(AclActionsInterface::ACCOUNT_EDIT, $id);

        $this->accountService->update($id, $this->accountForm->getItemData());

        $this->eventDispatcher->notify(new Event(
            'edit.account',
            $this,
            function () use ($id) {
                $accountDetails = $this->accountService->getByIdEnriched($id);

                return EventMessage::build(__u('Account updated'))
                                   ->addDetail(__u('Account'), $accountDetails->getName())
                                   ->addDetail(__u('Client'), $accountDetails->getClientName());
            }
        ));

        $this->updateCustomFieldsForItem(
            AclActionsInterface::ACCOUNT,
            $id,
            $this->request,
            $this->customFieldService
        );

        return ActionResponse::ok(
            __u('Account updated'),
            [
                'itemId' => $id,
                'nextAction' => $this->acl->getRouteFor(AclActionsInterface::ACCOUNT_VIEW),
            ]
        );
    }
}
