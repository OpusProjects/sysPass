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
use SP\Domain\Auth\Dtos\TotpProof;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Auth\Services\Totp;
use SP\Domain\User\Dtos\UserDto;
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
    public function check(UserDto $user): ?TotpProof
    {
        $userId = (int)$user->id;

        try {
            if (!$this->userTotpService->isEnabled($userId)) {
                return $this->isRequired($user) ? $this->checkEnrolment($user) : null;
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

            $proof = $this->userTotpService->check($userId, $code);
        } catch (AuthException $e) {
            throw $e;
        } catch (Exception $e) {
            throw AuthException::error(__u('Internal error'), null, self::STATUS_INTERNAL_ERROR, $e);
        }

        if ($proof === null) {
            $this->refuse();
        }

        return $proof;
    }

    /**
     * @inheritDoc
     */
    public function consume(int $userId, TotpProof $proof): array
    {
        try {
            $spent = $this->userTotpService->spend($userId, $proof);
        } catch (Exception $e) {
            throw AuthException::error(__u('Internal error'), null, self::STATUS_INTERNAL_ERROR, $e);
        }

        if (!$spent) {
            $this->refuse();
        }

        if ($proof->isRecoveryCode()) {
            // Worth a line in the event log of its own: it is how a lost phone, or a stolen code
            // sheet, shows up.
            $this->eventDispatcher->notify(
                new Event(
                    'login.totp.recovery',
                    $this,
                    EventMessage::build()->addDescription(__u('Signed in with a recovery code'))
                )
            );
        }

        if (!$proof->isEnrolment()) {
            return [];
        }

        $this->eventDispatcher->notify(
            new Event(
                'login.totp.enrol',
                $this,
                EventMessage::build()->addDescription(__u('Two-factor authentication turned on'))
            )
        );

        try {
            return $this->userTotpService->generateRecoveryCodes($userId);
        } catch (Exception $e) {
            // The second factor is on and the sign-in proved it; recovery codes can be issued
            // again from the Security tab, with a code, so this is not a reason to refuse.
            $this->eventDispatcher->notify(new Event('exception', $e));

            return [];
        }
    }

    /**
     * Whether an administrator requires this user to sign in with a second factor
     */
    private function isRequired(UserDto $user): bool
    {
        return $user->isTotpRequired === true || $this->config->getConfigData()->isTotpRequired();
    }

    /**
     * A user required to have a second factor who has none enrols it here, before the sign-in
     * completes: there is never a session that was opened with the password alone.
     *
     * The secret shown is the pending one when there is one, so trying again does not replace
     * what the user already scanned. Whoever holds the password can enrol — but whoever holds the
     * password could sign in outright before this requirement existed, and once a secret is
     * confirmed the account needs it.
     *
     * @throws AuthException
     * @throws Exception
     */
    private function checkEnrolment(UserDto $user): TotpProof
    {
        $userId = (int)$user->id;
        $secret = $this->userTotpService->pendingSecret($userId)
                  ?? $this->userTotpService->startEnrolment($userId);

        $data = [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, Totp::ISSUER, (string)$user->login),
        ];

        $code = $this->request->analyzeString('totp');

        if (empty($code)) {
            throw AuthException::info(
                __u('Two-factor sign-in is required: scan the code with your authenticator app'),
                null,
                LoginStatus::TOTP_ENROL_REQUIRED->value
            )->withData($data);
        }

        $proof = $this->userTotpService->checkPending($userId, $code);

        if ($proof === null) {
            $this->addTracking();

            throw AuthException::info(
                __u('Wrong two-factor code'),
                null,
                LoginStatus::TOTP_ENROL_REQUIRED->value
            )->withData($data);
        }

        return $proof;
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
