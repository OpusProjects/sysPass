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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;

/**
 * Class SaveEditController
 */
final class SaveEditController extends UserGroupSaveBase
{
    use ItemTrait;

    /**
     * Saves edit action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function saveEditAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::GROUP_EDIT)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            $this->form->validateFor(AclActionsInterface::GROUP_EDIT, $id);

            $groupData = $this->form->getItemData();

            $this->userGroupService->update($groupData);

            $this->eventDispatcher->notify(
                'edit.userGroup',
                new Event(
                    $this,
                    EventMessage::build()
                        ->addDescription(__u('Group updated'))
                        ->addDetail(__u('Name'), $groupData->getName())
                        ->addExtra('userGroupId', $id)
                )
            );

            $this->updateCustomFieldsForItem(AclActionsInterface::GROUP, $id, $this->request, $this->customFieldService);

            return ActionResponse::ok(__u('Group updated'));
        } catch (ValidationException $e) {
            return ActionResponse::error($e->getMessage());
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify('exception', new Event($e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
