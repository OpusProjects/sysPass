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

use function SP\__u;
use function SP\processException;

/**
 * Turn the second factor off, given a current code
 */
final class DisableController extends UserSettingsTotpBase
{
    #[Action(ResponseType::JSON)]
    public function disableAction(): ActionResponse
    {
        try {
            // Required by an administrator, turning it off would only send the user through
            // enrolment again at the next sign-in; say why instead.
            if ($this->session->getUserData()->isTotpRequired === true || $this->configData->isTotpRequired()) {
                return ActionResponse::error(__u('Your administrator requires two-factor sign-in'));
            }

            $this->requireCode();

            $this->userTotpService->disable($this->getUserId());

            $this->notify(__u('Two-factor authentication turned off'));

            return ActionResponse::ok(__u('Two-factor authentication is off'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
