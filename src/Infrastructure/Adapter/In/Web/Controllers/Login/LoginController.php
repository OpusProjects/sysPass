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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Login;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Application\Auth\Ports\LoginService;
use SP\Application\Auth\Services\Login;
use SP\Domain\Http\Providers\Uri;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class LoginController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class LoginController extends ControllerBase
{

    private Login $loginService;

    public function __construct(
        Application  $application,
        WebControllerHelper $webControllerHelper,
        LoginService $loginService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->loginService = $loginService;
    }

    /**
     * Login action
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function loginAction(): ActionResponse
    {
        try {
            $from = $this->getSignedUriFromRequest($this->request, $this->configData);

            $loginResponse = $this->loginService->doLogin($from);

            $this->checkForwarded();

            $redirector = function ($route) use ($from) {
                $uri = new Uri(ltrim($this->uriContext->getSubUri(), '/'));
                $uri->addParam('r', $route);

                if ($from !== null) {
                    return $uri->addParam('from', $from)->getUriSigned($this->configData->getPasswordSalt());
                }

                return $uri->getUri();
            };

            $this->eventDispatcher->notify(
                'login.finish',
                new Event($this, EventMessage::build()->addExtra('redirect', $redirector))
            );

            return ActionResponse::ok('', [
                                                     'url' => $this->session->getTrasientKey(
                                                         'redirect'
                                                     ) ?: $loginResponse->getRedirect(),
                                                 ]);
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify('exception', new Event($e));

            return ActionResponse::error($e->getMessage());
        }
    }

    /**
     * checkForwarded
     */
    private function checkForwarded(): void
    {
        $forward = $this->request->getForwardedFor();

        if ($forward !== null) {
            $this->eventDispatcher->notify(
                'login.info',
                new Event(
                    $this,
                    EventMessage::build()
                        ->addDetail(
                            'Forwarded',
                            $this->configData->isDemoEnabled() ? '***' : implode(',', $forward)
                        )
                )
            );
        }
    }
}
