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

namespace SP\Application\User\Services;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use SP\Application\Application;
use SP\Domain\Core\Messages\MailMessage;
use SP\Domain\Common\Providers\Password;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Html\Html;
use SP\Domain\User\Models\UserPassRecover as UserPassRecoverModel;
use SP\Domain\User\Ports\UserPassRecoverRepository;
use SP\Application\User\Ports\UserPassRecoverService;

use function SP\__;
use function SP\__u;

/**
 * Class UserPassRecover
 */
final class UserPassRecover extends Service implements UserPassRecoverService
{
    /**
     * Maximum time allowed to recover the password
     */
    private const MAX_PASS_RECOVER_TIME = 3600;
    /**
     * Maximum number of attempts allowed to recover the password
     */
    public const MAX_PASS_RECOVER_LIMIT = 3;

    /**
     * @param UserPassRecoverRepository<UserPassRecoverModel> $userPassRecoverRepository
     */
    public function __construct(
        Application                                $application,
        private readonly UserPassRecoverRepository $userPassRecoverRepository
    ) {
        parent::__construct($application);
    }

    /**
     * The mail carrying a one-time reset link, and the address that link points at.
     *
     * The base URI is decided here rather than taken from the caller, because both callers used to
     * pass `UriContextInterface::getWebUri()` — which prefers `Forwarded` / `X-Forwarded-Host`,
     * headers supplied by whoever made the request, with no `setTrustedProxies()` anywhere to
     * restrict them. Verified against the running instance: `X-Forwarded-Host: evil.example.com`
     * comes straight back out of the application.
     *
     * `saveRequestAction()` needs no session, so an unauthenticated caller who knows a login and
     * its email address could choose the host in the mail the real user then receives — a
     * legitimate message, from the real installation, whose link hands the one-time hash to
     * somebody else.
     *
     * Six other link builders in this application already prefer the configured application URL
     * (`AccountHelper`, `ViewLinkController`, `PublicLinkViewBase`, `AccountSearchItem`,
     * `Template`, `Account\SaveRequestController`); these two were the exception. The fallback is
     * the unforwarded host rather than `getWebUri()`, so the header cannot choose it even when no
     * application URL has been configured.
     */
    public static function getMailMessage(
        string              $hash,
        ConfigDataInterface $configData,
        UriContextInterface $uriContext
    ): MailMessage {
        $baseUri = $configData->getApplicationUrl() ?: $uriContext->getUnforwardedWebUri();

        $mailMessage = new MailMessage();
        $mailMessage->setTitle(__('Password Change'));
        $mailMessage->addDescription(__('A request for changing your user password has been done.'));
        $mailMessage->addDescriptionLine();
        $mailMessage->addDescription(__('In order to complete the process, please go to this URL:'));
        $mailMessage->addDescriptionLine();
        $mailMessage->addDescription(
            Html::anchorText(sprintf('%s/index.php?r=userPassReset/reset/%s', $baseUri, $hash))
        );
        $mailMessage->addDescriptionLine();
        $mailMessage->addDescription(__('If you have not requested this action, please dismiss this message.'));

        return $mailMessage;
    }

    /**
     * @throws SPException
     * @throws ServiceException
     */
    /**
     * Spend every token outstanding for this user, because their password has just changed.
     *
     * @param int $userId
     *
     * @return int how many were still outstanding
     * @throws ConstraintException
     * @throws QueryException
     */
    public function toggleUsedByUserId(int $userId): int
    {
        return $this->userPassRecoverRepository->toggleUsedByUserId($userId);
    }

    public function toggleUsedByHash(string $hash): void
    {
        $time = time() - self::MAX_PASS_RECOVER_TIME;

        if ($this->userPassRecoverRepository->toggleUsedByHash($hash, $time) === 0) {
            throw ServiceException::info(__u('Wrong hash or expired'));
        }
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     * @throws EnvironmentIsBrokenException
     */
    public function requestForUserId(int $id): string
    {
        if ($this->checkAttemptsByUserId($id)) {
            throw ServiceException::warning(__u('Attempts exceeded'));
        }

        $hash = Password::generateRandomBytes(16);

        $this->add($id, $hash);

        return $hash;
    }

    /**
     * Check the password recovery limit.
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    private function checkAttemptsByUserId(int $userId): bool
    {
        $time = time() - self::MAX_PASS_RECOVER_TIME;

        return $this->userPassRecoverRepository->getAttemptsByUserId($userId, $time) >= self::MAX_PASS_RECOVER_LIMIT;
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function add(int $userId, string $hash): void
    {
        $this->userPassRecoverRepository->add($userId, $hash);
    }

    /**
     * Check the password recovery hash.
     *
     * @param string $hash
     * @return int
     * @throws ServiceException
     */
    public function getUserIdForHash(string $hash): int
    {
        $time = time() - self::MAX_PASS_RECOVER_TIME;
        $result = $this->userPassRecoverRepository->getUserIdForHash($hash, $time);

        if ($result->getNumRows() === 0) {
            throw ServiceException::info(__u('Wrong hash or expired'));
        }

        return $result->getData(UserPassRecoverModel::class)->getUserId();
    }
}
