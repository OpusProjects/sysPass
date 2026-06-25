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
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use function SP\__u;
use function SP\processException;

/**
 * Class DeleteController
 */
final class DeleteController extends UserSaveBase
{
    use ItemTrait;


    /**
     * Delete action
     *
     * @param  int|null  $id
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function deleteAction(?int $id = null): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::USER_DELETE)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            if ($id === null) {
                $ids = $this->getItemsIdFromRequest($this->request);
                $this->userService->deleteByIdBatch($ids);
                $this->deleteCustomFieldsForItem(AclActionsInterface::USER, $ids, $this->customFieldService);

                $this->eventDispatcher->notify(new Event('delete.user.selection',
                        $this,
                        EventMessage::build()
                            ->addDescription(__u('Users deleted'))
                            ->setExtra('userId', $ids)
                    )
                );

                return ActionResponse::ok(__u('Users deleted'));
            }

            $this->userService->delete($id);

            $this->deleteCustomFieldsForItem(AclActionsInterface::USER, $id, $this->customFieldService);

            $this->eventDispatcher->notify(new Event('delete.user', 
                    $this,
                    EventMessage::build()
                        ->addDescription(__u('User deleted'))
                        ->addDetail(__u('User'), $id)
                        ->addExtra('userId', $id)
                )
            );

            return ActionResponse::ok(__u('User deleted'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
