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

use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Application\Auth\Ports\LoginService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\processException;

/**
 * Class LoginController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class LoginController extends ControllerBase
{

    private LoginService $loginService;

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
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function loginAction(): ActionResponse
    {
        try {
            $from = $this->getSignedUriFromRequest($this->request, $this->configData);

            $loginResponse = $this->loginService->doLogin($from);

            $this->checkForwarded();

            $this->eventDispatcher->notify(new Event(
                'login.finish',
                $this,
                EventMessage::build()->addExtra('from', $from)
            ));

            $data = ['url' => $this->session->getTrasientKey('redirect') ?: $loginResponse->getRedirect()];

            // An enrolment completed by this sign-in: the form shows the codes before going on.
            if ($loginResponse->getRecoveryCodes() !== []) {
                $data['recoveryCodes'] = $loginResponse->getRecoveryCodes();
            }

            return ActionResponse::ok('', $data);
        } catch (AuthException $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage(), self::promptFor($e));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }

    /**
     * The field the form has to show next, for a refusal that asks for one
     *
     * The login form shows the master password field for one status and the previous password
     * field for another — but every refusal used to be answered as a plain ERROR, which carries
     * neither, so those branches of the form never ran. A user who had to supply their master
     * password, or their previous password after a reset, was told it was wrong and never shown
     * where to type it. Only those statuses are passed on: the form reads 0 as success.
     *
     * @return array<string, mixed>|null
     */
    private static function promptFor(AuthException $e): ?array
    {
        $status = LoginStatus::tryFrom((int)$e->getCode());

        return match ($status) {
            LoginStatus::INVALID_MASTER_PASS,
            LoginStatus::OLD_PASS_REQUIRED,
            LoginStatus::TOTP_REQUIRED => ['loginStatus' => $status->value],
            // The enrolment prompt also carries the secret, for the form to draw its QR code.
            LoginStatus::TOTP_ENROL_REQUIRED => ['loginStatus' => $status->value] + $e->getData(),
            default => null,
        };
    }

    /**
     * checkForwarded
     */
    private function checkForwarded(): void
    {
        $forward = $this->request->getForwardedFor();

        if ($forward !== null) {
            $this->eventDispatcher->notify(new Event(
                'login.info',
                $this,
                EventMessage::build()
                        ->addDetail(
                            'Forwarded',
                            $this->configData->isDemoEnabled() ? '***' : implode(',', $forward)
                        )
            ));
        }
    }
}
