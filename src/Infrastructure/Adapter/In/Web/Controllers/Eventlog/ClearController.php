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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Application\Security\Ports\EventlogService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;
use function SP\processException;

/**
 * Class ClearController
 */
final class ClearController extends ControllerBase
{

    private EventlogService $eventlogService;

    /**
     * @throws SessionTimeout
     * @throws AuthException
     */
    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        EventlogService $eventlogService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->eventlogService = $eventlogService;
    }


    /**
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function clearAction(): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::EVENTLOG_CLEAR)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->eventlogService->clear();

            $this->eventDispatcher->notify(new Event('clear.eventlog', $this, EventMessage::build()->addDescription(__u('Event log cleared'))));

            return ActionResponse::ok(__u('Event log cleared'));
        } catch (Exception $e) {
            processException($e);

            return ActionResponse::error($e->getMessage());
        }
    }
}
