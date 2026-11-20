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

use Exception;
use SP\Application\Application;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Services\Totp;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\User\Models\UserTotp as UserTotpModel;
use SP\Domain\User\Ports\UserTotpRepository;

use function SP\__u;
use function SP\processException;

/**
 * A user's second factor: enrolment, verification at sign-in, removal
 *
 * The secret is stored encrypted with a key derived from the installation's password salt. The
 * salt lives in config.xml, not in the database, so a database dump on its own — a leaked backup
 * of the dump, a read-only SQL injection somewhere — does not yield anybody's second factor. It
 * cannot be sealed with anything the user holds, as the master password is: sign-in has to read
 * it before the user has proven anything beyond their password.
 */
final class UserTotp extends Service implements UserTotpService
{
    /**
     * @param UserTotpRepository<UserTotpModel> $userTotpRepository
     */
    public function __construct(
        Application                         $application,
        private readonly UserTotpRepository $userTotpRepository,
        private readonly CryptInterface     $crypt
    ) {
        parent::__construct($application);
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(int $userId): bool
    {
        return $this->getRow($userId)?->isEnabled() === true;
    }

    /**
     * @inheritDoc
     */
    public function verify(int $userId, string $code, ?int $time = null): ?int
    {
        $row = $this->getRow($userId);

        if ($row === null || !$row->isEnabled()) {
            return null;
        }

        return Totp::verify($this->decryptSecret($row), $code, $time ?? time(), $row->getLastStep());
    }

    /**
     * @inheritDoc
     */
    public function consume(int $userId, int $step): bool
    {
        try {
            return $this->userTotpRepository->consumeStep($userId, $step) === 1;
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while checking the code'), null, 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function startEnrolment(int $userId): string
    {
        if ($this->isEnabled($userId)) {
            throw ServiceException::error(__u('Two-factor authentication is already on'));
        }

        $secret = Totp::generateSecret();
        $salt = $this->getSalt();

        try {
            $secretKey = $this->crypt->makeSecuredKey($salt);

            $this->userTotpRepository->replace(
                new UserTotpModel([
                                      'userId' => $userId,
                                      'secret' => $this->crypt->encrypt($secret, $secretKey, $salt),
                                      'secretKey' => $secretKey,
                                  ])
            );
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while saving the two-factor secret'), null, 0, $e);
        }

        return $secret;
    }

    /**
     * @inheritDoc
     */
    public function confirmEnrolment(int $userId, string $code, ?int $time = null): bool
    {
        $row = $this->getRow($userId);

        if ($row === null || $row->isEnabled()) {
            return false;
        }

        $step = Totp::verify($this->decryptSecret($row), $code, $time ?? time());

        if ($step === null) {
            return false;
        }

        try {
            // Only a pending row is matched: a second confirmation, or one racing a fresh
            // enrolment that replaced this secret, changes nothing and is refused.
            return $this->userTotpRepository->enable($userId, $step) === 1;
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while saving the two-factor secret'), null, 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function disable(int $userId): void
    {
        try {
            $this->userTotpRepository->deleteByUserId($userId);
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while removing the two-factor secret'), null, 0, $e);
        }
    }

    /**
     * @throws ServiceException
     */
    private function getRow(int $userId): ?UserTotpModel
    {
        try {
            $result = $this->userTotpRepository->getByUserId($userId);
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while checking the code'), null, 0, $e);
        }

        if ($result->getNumRows() === 0) {
            return null;
        }

        /** @var UserTotpModel */
        return $result->getData(UserTotpModel::class);
    }

    /**
     * @throws ServiceException
     */
    private function decryptSecret(UserTotpModel $row): string
    {
        try {
            return $this->crypt->decrypt($row->getSecret() ?? '', $row->getSecretKey() ?? '', $this->getSalt());
        } catch (Exception $e) {
            processException($e);

            throw ServiceException::error(__u('Error while checking the code'), null, 0, $e);
        }
    }

    /**
     * @throws ServiceException
     */
    private function getSalt(): string
    {
        $salt = $this->config->getConfigData()->getPasswordSalt();

        if (empty($salt)) {
            throw ServiceException::error(__u('Error while checking the code'), __u('No password salt is set'));
        }

        return $salt;
    }
}
