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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Domain\Account\Models\PublicLink;
use SP\Domain\Account\PublicLinkType;
use SP\Domain\Common\Providers\Password;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SPException;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveCreateFromAccountController
 */
final class SaveCreateFromAccountController extends PublicLinkSaveBase
{

    /**
     * Saves create action
     *
     * @param int $accountId
     * @param int $notify
     *
     * @return bool
     * @throws JsonException
     * @throws SPException
     */
    #[Action(ResponseType::JSON)]
    public function saveCreateFromAccountAction(int $accountId, int $notify): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PUBLICLINK_CREATE)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            $publicLinkData = new PublicLink(
                [
                    'typeId' => PublicLinkType::Account->value,
                    'itemId' => $accountId,
                    'notify' => (bool)$notify,
                    'hash' => Password::generateRandomBytes()
                ]
            );

            $this->publicLinkService->create($publicLinkData);

            $this->eventDispatcher->notify(new Event('create.publicLink.account', $this));

            return ActionResponse::ok(__u('Link created'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
