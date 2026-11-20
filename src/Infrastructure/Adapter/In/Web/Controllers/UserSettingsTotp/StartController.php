<?php
declare(strict_types=1);
/**
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserSettingsTotp;

use Exception;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Events\Event;
use SP\Domain\Auth\Services\Totp;

use function SP\__u;
use function SP\processException;

/**
 * Start an enrolment: a new secret, pending until a code from it is confirmed
 */
final class StartController extends UserSettingsTotpBase
{
    #[Action(ResponseType::JSON)]
    public function startAction(): ActionResponse
    {
        try {
            $secret = $this->userTotpService->startEnrolment($this->getUserId());

            return ActionResponse::ok(__u('Scan the code with your authenticator app'), [
                'secret' => $secret,
                'uri' => Totp::provisioningUri(
                    $secret,
                    Totp::ISSUER,
                    (string)$this->session->getUserData()->login
                ),
            ]);
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
