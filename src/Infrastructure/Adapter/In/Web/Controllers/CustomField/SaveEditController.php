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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\CustomField;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveEditController
 */
final class SaveEditController extends CustomFieldSaveBase
{

    /**
     * Saves edit action
     *
     * @param int $id
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function saveEditAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::CUSTOMFIELD_EDIT)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->form->validateFor(AclActionsInterface::CUSTOMFIELD_EDIT, $id);

            $itemData = $this->form->getItemData();

            $customFieldDefinition = $this->customFieldDefService->getById($itemData->getId());

            if ($customFieldDefinition->getModuleId() !== $itemData->getModuleId()) {
                $this->customFieldDefService->changeModule($customFieldDefinition);
            } else {
                $this->customFieldDefService->update($itemData);
            }

            $this->eventDispatcher->notify(new Event(
                'edit.customField',
                $this,
                EventMessage::build()
                        ->addDescription(__u('Field updated'))
                        ->addDetail(__u('Field'), $itemData->getName())
            ));

            return ActionResponse::ok(__u('Field updated'));
        } catch (ValidationException $e) {
            return ActionResponse::error($e->getMessage());
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
