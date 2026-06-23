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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Domain\Core\Exceptions\ValidationException;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveResetController
 */
final class SaveResetController extends UserPassResetSaveBase
{

    /**
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function saveResetAction(): ActionResponse
    {
        try {
            $this->checkTracking();

            $pass = $this->request->analyzeEncrypted('password');
            $passR = $this->request->analyzeEncrypted('password_repeat');

            if (!$pass || !$passR) {
                throw new ValidationException(__u('Password cannot be blank'));
            }

            if ($pass !== $passR) {
                throw new ValidationException(__u('Passwords do not match'));
            }

            $hash = $this->request->analyzeString('hash');

            $userId = $this->userPassRecoverService->getUserIdForHash($hash);

            $this->userPassRecoverService->toggleUsedByHash($hash);

            $this->userService->updatePass($userId, $pass);

            $user = $this->userService->getById($userId);

            $this->eventDispatcher->notify(new Event('edit.user.password', 
                    $this,
                    EventMessage::build()
                        ->addDescription(__u('Password updated'))
                        ->addDetail(__u('User'), $user->getLogin())
                        ->addExtra('userId', $userId)
                        ->addExtra('email', $user->getEmail())
                )
            );

            return ActionResponse::ok(__u('Password updated'));
        } catch (Exception $e) {
            processException($e);

            $this->addTracking();

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
