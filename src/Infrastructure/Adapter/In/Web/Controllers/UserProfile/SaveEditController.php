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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserProfile;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use function SP\__u;
use function SP\processException;

/**
 * Class SaveEditController
 */
final class SaveEditController extends UserProfileSaveBase
{
    use ItemTrait;


    /**
     * Saves edit action
     *
     * @param  int  $id
     *
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function saveEditAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PROFILE_EDIT)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->form->validateFor(AclActionsInterface::PROFILE_EDIT, $id);

            $profileData = $this->form->getItemData();

            $this->userProfileService->update($profileData);

            $this->eventDispatcher->notify(new Event(
                'edit.userProfile',
                $this,
                EventMessage::build()
                        ->addDescription(__u('Profile updated'))
                        ->addDetail(__u('Name'), $profileData->getName())
                        ->addExtra('userProfileId', $id)
            ));

            $this->updateCustomFieldsForItem(AclActionsInterface::PROFILE, $id, $this->request, $this->customFieldService);

            return ActionResponse::ok(__u('Profile updated'));
        } catch (ValidationException $e) {
            return ActionResponse::error($e->getMessage());
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
