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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Login;

use SP\Infrastructure\Context\ContextBase;
use SP\Infrastructure\Context\SessionLifecycleHandler;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;

use function SP\__u;

/**
 * Class LoginController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class LogoutController extends ControllerBase
{
    /**
     * Logout action
     */
    #[Action(ResponseType::PLAIN_TEXT)]
    public function logoutAction(): ActionResponse
    {
        if ($this->session->isLoggedIn() === true) {
            $inactiveTime = abs(round((time() - $this->session->getLastActivity()) / 60, 2));
            $totalTime = abs(round((time() - $this->session->getStartActivity()) / 60, 2));

            $this->eventDispatcher->notify(new Event(
                'logout',
                $this,
                EventMessage::build()
                        ->addDescription(__u('Logout session'))
                        ->addDetail(__u('User'), $this->session->getUserData()->login)
                        ->addDetail(__u('Inactive time'), $inactiveTime.' min.')
                        ->addDetail(__u('Total time'), $totalTime.' min.')
            ));

            SessionLifecycleHandler::clean();

            $this->session->setAppStatus(ContextBase::APP_STATUS_LOGGEDOUT);

            $this->layoutHelper->getCustomLayout('logout', 'logout');

            return ActionResponse::ok($this->render());
        } else {
            $this->router->response()->redirect('index.php?r=login')->send();

            return ActionResponse::ok('');
        }
    }
}
