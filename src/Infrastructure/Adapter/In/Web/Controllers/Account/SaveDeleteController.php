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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Account;

use SP\Application\Application;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Domain\CustomField\Models\CustomFieldData as CustomFieldDataModel;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountAclEnforcer;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;

/**
 * Class SaveDeleteController
 */
final class SaveDeleteController extends AccountControllerBase
{
    use ItemTrait;

    /**
     * @param CustomFieldDataService<CustomFieldDataModel> $customFieldService
     */
    public function __construct(
        Application                             $application,
        WebControllerHelper                     $webControllerHelper,
        private readonly AccountService         $accountService,
        private readonly CustomFieldDataService $customFieldService,
        private readonly AccountAclEnforcer     $accountAclEnforcer
    ) {
        parent::__construct($application, $webControllerHelper);
    }

    /**
     * Saves delete action
     *
     * @param int $id Account's ID
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function saveDeleteAction(int $id): ActionResponse
    {
        $this->accountAclEnforcer->checkAccountAccess(AclActionsInterface::ACCOUNT_DELETE, $id);

        $accountDetails = $this->accountService->getByIdEnriched($id);

        $this->accountService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.account',
            $this,
            EventMessage::build(__u('Account removed'))
                            ->addDetail(__u('Account'), $accountDetails->getName())
                            ->addDetail(__u('Client'), $accountDetails->getClientName())
        ));

        $this->deleteCustomFieldsForItem(AclActionsInterface::ACCOUNT, $id, $this->customFieldService);

        return ActionResponse::ok(__u('Account removed'));
    }
}
