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

use SP\Application\Application;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;

use function SP\__u;

/**
 * A signed-in user managing their own second factor
 *
 * Every action acts on the user in the session and takes no user id, so there is nobody else's
 * to reach. Those that weaken the second factor — turning it off, replacing the recovery codes —
 * ask for a current code first: a session left open, or taken, is not enough to remove it.
 */
abstract class UserSettingsTotpBase extends SimpleControllerBase
{
    public function __construct(
        Application                         $application,
        SimpleControllerHelper              $simpleControllerHelper,
        protected readonly UserTotpService $userTotpService
    ) {
        parent::__construct($application, $simpleControllerHelper);

        $this->checks();
    }

    protected function getUserId(): int
    {
        return (int)$this->session->getUserData()->id;
    }

    /**
     * Spend the code sent as `code`, a one-time code or a recovery code
     *
     * @throws ServiceException When it is missing, wrong or already used
     */
    protected function requireCode(): void
    {
        $typed = (string)$this->request->analyzeString('code', '');
        $proof = $typed === '' ? null : $this->userTotpService->check($this->getUserId(), $typed);

        if ($proof === null || !$this->userTotpService->spend($this->getUserId(), $proof)) {
            $this->eventDispatcher->notify(
                new Event('userSettings.totp', $this, EventMessage::build()->addDescription(__u('Wrong two-factor code')))
            );

            throw ServiceException::error(__u('Wrong two-factor code'));
        }
    }

    protected function notify(string $description): void
    {
        $this->eventDispatcher->notify(
            new Event(
                'userSettings.totp',
                $this,
                EventMessage::build()
                            ->addDescription($description)
                            ->addDetail(__u('User'), (string)$this->session->getUserData()->login)
            )
        );
    }
}
