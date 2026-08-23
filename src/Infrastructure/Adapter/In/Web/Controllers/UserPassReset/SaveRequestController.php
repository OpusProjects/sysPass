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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\User\Services\UserPassRecover;

use function SP\__u;
use function SP\processException;
use function SP\__;

/**
 * Class SaveRequestController
 */
final class SaveRequestController extends UserPassResetSaveBase
{

    /**
     * Ask for a password-recovery link.
     *
     * Every outcome answers the same thing. This endpoint needs no session, so whatever it
     * distinguishes it distinguishes for anybody: it used to answer "User not found" for a login
     * that does not exist, "Wrong data" for one that does with the wrong address, "Unable to reset
     * the password" for a disabled or LDAP account, and "Request sent" when it worked. That is an
     * oracle over the whole user table — whether a login exists, which address is on file for it,
     * and whether it is usable — offered to an unauthenticated caller, one request at a time.
     *
     * Half of it had already been closed: the disabled and LDAP refusals were collapsed into one
     * message for exactly this reason, and the test that pinned them says so. Collapsing two of
     * four still left the first question answerable, so this finishes it.
     *
     * The sibling gets it right and settles the shape: Login answers "Wrong login" for an unknown
     * user and for a wrong password alike, rather than saying which.
     *
     * The rate limit is deliberately still distinguishable. "Attempts exceeded" is about the
     * caller's own behaviour and reveals nothing about any account, and hiding it would leave
     * somebody who has locked themselves out with no way to find out why.
     *
     * What is lost is the message telling an honest user they mistyped their address, and the one
     * telling a disabled user to contact an administrator. Both are recoverable — the address is
     * theirs to check, and a disabled account is a conversation with an administrator either way —
     * whereas an enumerable user list is not. The real outcome is still recorded in the event log,
     * where an administrator can see it and a stranger cannot.
     *
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function saveRequestAction(): ActionResponse
    {
        try {
            $this->checkTracking();
        } catch (Exception $e) {
            processException($e);

            // Still counted. checkTracking() throws once the limit is already reached, and
            // recording the attempt anyway is what makes further hammering extend the block
            // rather than sit out a window that stops growing.
            $this->addTracking();

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }

        $login = $this->request->analyzeString('login');
        $email = $this->request->analyzeEmail('email');

        try {
            $userData = $this->userService->getByLogin($login);

            if ($userData->getEmail() !== $email) {
                throw new SPException(__u('Wrong data'), SPException::WARNING);
            }

            if ($userData->isDisabled() || $userData->isLdap()) {
                throw new SPException(
                    __u('Unable to reset the password'),
                    SPException::WARNING,
                    __u('Please contact to the administrator')
                );
            }

            $hash = $this->userPassRecoverService->requestForUserId($userData->getId());

            $this->eventDispatcher->notify(new Event(
                'request.user.passReset',
                $this,
                EventMessage::build()
                        ->addDescription(__u('Password Recovery'))
                        ->addDetail(__u('Requested for'), sprintf('%s (%s)', $login, $email))
            ));

            $this->mailService->send(
                __('Password Change'),
                $email,
                UserPassRecover::getMailMessage($hash, $this->uriContext->getWebUri())
            );
        } catch (Exception $e) {
            // Recorded and counted, not reported. The tracking still runs, so guessing is still
            // rate limited; only the answer the guesser gets back is the same either way.
            processException($e);

            $this->addTracking();

            $this->eventDispatcher->notify(new Event('exception', $e));
        }

        return ActionResponse::ok(
            __u('Request sent'),
            [__u('You will receive an email to complete the request shortly.')]
        );
    }
}
