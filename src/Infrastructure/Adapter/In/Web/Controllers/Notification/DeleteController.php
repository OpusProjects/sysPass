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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Notification;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;

/**
 * Class DeleteController
 */
final class DeleteController extends NotificationSaveBase
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
            if ($id === null) {
                if ($this->userDto->getIsAdminApp()) {
                    $this->notificationService->deleteAdminBatch($this->getItemsIdFromRequest($this->request));
                } else {
                    $this->notificationService->deleteByIdBatch($this->getItemsIdFromRequest($this->request));
                }

                $this->eventDispatcher->notify(
                    'delete.notification.selection',
                    new Event($this, EventMessage::build()->addDescription(__u('Notifications deleted')))
                );

                return ActionResponse::ok(__u('Notifications deleted'));
            }

            if ($this->userDto->getIsAdminApp()) {
                $this->notificationService->deleteAdmin($id);
            } else {
                $this->notificationService->delete($id);
            }

            $this->eventDispatcher->notify(
                'delete.notification',
                new Event(
                    $this,
                    EventMessage::build()
                        ->addDescription(__u('Notification deleted'))
                        ->addDetail(__u('Notification'), $id)
                )
            );

            return ActionResponse::ok(__u('Notification deleted'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify('exception', new Event($e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
