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

namespace SP\Application\Auth\Services;

use Exception;
use SP\Application\Application;
use SP\Application\Auth\Ports\LoginTotpService;
use SP\Application\Security\Ports\TrackService;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Http\Ports\RequestService;

use function SP\__u;

/**
 * The second factor at sign-in
 *
 * Checked after the password and before the master password, and spent only once everything
 * else has passed. Spending it at the check would burn the code whenever a later step asks for
 * something more — the master password prompt — and the user would have to wait for the next
 * one to answer it. A code that was checked and not spent is still bound to the step it belongs
 * to, so it is good for at most the same ninety seconds either way.
 */
final class LoginTotp extends LoginBase implements LoginTotpService
{
    public function __construct(
        Application                      $application,
        TrackService                     $trackService,
        RequestService                   $request,
        private readonly UserTotpService $userTotpService
    ) {
        parent::__construct($application, $trackService, $request);
    }

    /**
     * @inheritDoc
     */
    public function check(int $userId): ?int
    {
        try {
            if (!$this->userTotpService->isEnabled($userId)) {
                return null;
            }

            $code = $this->request->analyzeString('totp');

            if (empty($code)) {
                // Not a failed attempt: the form does not show the field until it is asked for.
                throw AuthException::info(
                    __u('Two-factor code needed'),
                    null,
                    LoginStatus::TOTP_REQUIRED->value
                );
            }

            $step = $this->userTotpService->verify($userId, $code);
        } catch (AuthException $e) {
            throw $e;
        } catch (Exception $e) {
            throw AuthException::error(__u('Internal error'), null, self::STATUS_INTERNAL_ERROR, $e);
        }

        if ($step === null) {
            $this->refuse();
        }

        return $step;
    }

    /**
     * @inheritDoc
     */
    public function consume(int $userId, int $step): void
    {
        try {
            $spent = $this->userTotpService->consume($userId, $step);
        } catch (Exception $e) {
            throw AuthException::error(__u('Internal error'), null, self::STATUS_INTERNAL_ERROR, $e);
        }

        if (!$spent) {
            $this->refuse();
        }
    }

    /**
     * A wrong or already-used code is a failed sign-in, counted like a wrong password
     *
     * @throws AuthException
     */
    private function refuse(): never
    {
        $this->eventDispatcher->notify(
            new Event('login.totp', $this, EventMessage::build()->addDescription(__u('Wrong two-factor code')))
        );

        $this->addTracking();

        throw AuthException::info(__u('Wrong two-factor code'), null, LoginStatus::TOTP_REQUIRED->value);
    }
}
