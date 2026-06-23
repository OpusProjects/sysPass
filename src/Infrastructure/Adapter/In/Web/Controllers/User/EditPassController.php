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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\User;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\User;

/**
 * Class EditPassController
 */
final class EditPassController extends UserViewBase
{

    /**
     * Edit user's pass action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function editPassAction(int $id): ActionResponse
    {
        try {
            // Check whether the user to modify is different from the session user
            if (!$this->acl->checkUserAccess(AclActionsInterface::USER_EDIT_PASS, $id)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            $this->view->addTemplate('user_pass', 'itemshow');

            $this->view->assign('header', __('Password Change'));
            $this->view->assign('isView', false);
            $this->view->assign('route', 'user/saveEditPass/'.$id);

            $user = $id
                ? $this->userService->getById($id)
                : new User();

            $this->view->assign('user', $user);

            $this->eventDispatcher->notify(new Event('show.user.editPass', $this));

            return ActionResponse::ok('', ['html' => $this->render()]);
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
