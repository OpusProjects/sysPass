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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\AccountFile;

use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;

use function SP\__u;

/**
 * Class DeleteController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class DeleteController extends AccountFileBase
{
    use ItemTrait;

    /**
     * Delete action
     *
     * @param int|null $id
     *
     * @return ActionResponse
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function deleteAction(?int $id): ActionResponse
    {
        if ($id === null) {
            $ids = $this->getItemsIdFromRequest($this->request);

            if (empty($ids)) {
                return ActionResponse::error(__u('No items selected'));
            }

            foreach ($ids as $fileId) {
                $this->accountFileAcl->requireEdit(
                    $this->accountFileService->getById((int)$fileId)->accountId ?? 0
                );
            }

            $this->accountFileService->deleteByIdBatch($ids);

            $this->eventDispatcher->notify(new Event('delete.accountFile.selection', $this, EventMessage::build()->addDescription(__u('Files deleted'))));

            return ActionResponse::ok(__u('Files deleted'));
        }

        $this->accountFileAcl->requireEdit($this->accountFileService->getById($id)->accountId ?? 0);

        $this->accountFileService->delete($id);

        $this->eventDispatcher->notify(new Event(
            'delete.accountFile',
            $this,
            EventMessage::build(__u('File deleted'))->addDetail(__u('File'), $id)
        ));

        return ActionResponse::ok(__u('File deleted'));
    }
}
