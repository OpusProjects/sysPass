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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\CustomField;

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
final class DeleteController extends CustomFieldSaveBase
{
    use ItemTrait;


    /**
     * Delete action
     *
     * @param  int|null  $id
     *
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function deleteAction(?int $id = null): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::CUSTOMFIELD_DELETE)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            if ($id === null) {
                $ids = $this->getItemsIdFromRequest($this->request);

                if (empty($ids)) {
                    return ActionResponse::error(__u('No items selected'));
                }

                $this->customFieldDefService->deleteByIdBatch($ids);

                $this->eventDispatcher->notify(new Event('delete.customField.selection', $this, EventMessage::build()->addDescription(__u('Fields deleted'))));

                return ActionResponse::ok(__u('Fields deleted'));
            }

            $this->customFieldDefService->delete($id);

            $this->eventDispatcher->notify(new Event('delete.customField', $this));

            return ActionResponse::ok(__u('Field deleted'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
