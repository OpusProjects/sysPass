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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\User;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveEditPassController
 */
final class SaveEditPassController extends UserSaveBase
{

    /**
     * Saves edit action
     *
     * @param  int  $id
     *
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function saveEditPassAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::USER_EDIT_PASS, $id)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->form->validateFor(AclActionsInterface::USER_EDIT_PASS, $id);

            $itemData = $this->form->getItemData();

            $this->userService->updatePass($id, $itemData->getPass() ?? '');

            $this->eventDispatcher->notify(new Event(
                'edit.user.pass',
                $this,
                EventMessage::build()
                        ->addDescription(__u('Password updated'))
                        ->addDetail(__u('User'), $id)
            ));

            return ActionResponse::ok(__u('Password updated'));
        } catch (ValidationException $e) {
            return ActionResponse::error($e->getMessage());
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
