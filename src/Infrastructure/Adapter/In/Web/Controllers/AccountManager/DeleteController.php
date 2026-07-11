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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\AccountManager;

use SP\Infrastructure\Application;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Application\Account\Ports\AccountService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Domain\CustomField\Models\CustomFieldData as CustomFieldDataModel;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;

/**
 * Class AccountManagerController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class DeleteController extends ControllerBase
{
    use ItemTrait;

    /**
     * @param CustomFieldDataService<CustomFieldDataModel> $customFieldService
     * @throws AuthException
     * @throws SessionTimeout
     */
    public function __construct(
        Application                             $application,
        WebControllerHelper                     $webControllerHelper,
        private readonly AccountService         $accountService,
        private readonly CustomFieldDataService $customFieldService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();
    }

    /**
     * Delete action
     *
     * @param int|null $id
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function deleteAction(?int $id = null): ActionResponse
    {
        if (!$this->acl->checkUserAccess(AclActionsInterface::ACCOUNTMGR)) {
            return ActionResponse::error(__u('You don\'t have permission to do this operation'));
        }

        if ($id === null) {
            $ids = $this->getItemsIdFromRequest($this->request);

            if (empty($ids)) {
                return ActionResponse::error(__u('No items selected'));
            }

            $this->accountService->deleteByIdBatch($ids);

            $this->deleteCustomFieldsForItem(AclActionsInterface::ACCOUNT, $ids, $this->customFieldService);

            $this->eventDispatcher->notify(new Event('delete.account.selection', $this, EventMessage::build()->addDescription(__u('Accounts removed'))));

            return ActionResponse::ok(__u('Accounts removed'));
        }

        $accountView = $this->accountService->getByIdEnriched($id);

        $this->accountService->delete($id);

        $this->deleteCustomFieldsForItem(AclActionsInterface::ACCOUNT, $id, $this->customFieldService);

        $this->eventDispatcher->notify(new Event(
            'delete.account',
            $this,
            EventMessage::build(__u('Account removed'))
                            ->addDetail(__u('Account'), $accountView->getName())
                            ->addDetail(__u('Client'), $accountView->getClientName())
        ));

        return ActionResponse::ok(__u('Account removed'));
    }
}
