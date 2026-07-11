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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Infrastructure\Events\Event;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveCreate
 */
final class SaveCreateController extends PublicLinkSaveBase
{

    /**
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function saveCreateAction(): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PUBLICLINK_CREATE)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->form->validateFor(AclActionsInterface::PUBLICLINK_CREATE);

            $this->publicLinkService->create($this->form->getItemData());

            $this->eventDispatcher->notify(new Event('create.publicLink', $this));

            return ActionResponse::ok(__u('Link created'));
        } catch (ValidationException $e) {
            return ActionResponse::error($e->getMessage());
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
