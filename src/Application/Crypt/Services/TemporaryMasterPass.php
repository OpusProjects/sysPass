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

namespace SP\Application\Crypt\Services;

use Exception;
use SP\Core\Application;
use SP\Core\Crypt\Hash;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Messages\MailMessage;
use SP\Domain\Common\Providers\Password;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Config\Dtos\ConfigRequest;
use SP\Application\Config\Ports\ConfigService;
use SP\Domain\Core\AppInfoInterface;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Application\Crypt\Ports\TemporaryMasterPassService;
use SP\Application\Notification\Ports\MailService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;

use function SP\__;
use function SP\__u;
use function SP\processException;

/**
 * Class TemporaryMasterPass
 */
final class TemporaryMasterPass extends Service implements TemporaryMasterPassService
{
    /**
     * Maximum number of attempts
     */
    public const MAX_ATTEMPTS = 50;
    /**
     * Configuration parameters
     */
    private const PARAM_PASS     = 'tempmaster_pass';
    private const PARAM_KEY      = 'tempmaster_passkey';
    private const PARAM_HASH     = 'tempmaster_passhash';
    public const  PARAM_TIME     = 'tempmaster_passtime';
    public const  PARAM_MAX_TIME = 'tempmaster_maxtime';
    public const  PARAM_ATTEMPTS = 'tempmaster_attempts';

    private ?int $maxTime = null;

    public function __construct(
        Application                     $application,
        private readonly ConfigService  $configService,
        private readonly UserService    $userService,
        private readonly MailService    $mailService,
        private readonly CryptInterface $crypt,
    ) {
        parent::__construct($application);
    }


    /**
     * Creates a temporary key to encrypt the master password and store it.
     *
     * @param int $maxTime The maximum validity time of the key
     *
     * @return string
     * @throws ServiceException
     */
    public function create(int $maxTime = 14400): string
    {
        try {
            $this->maxTime = time() + $maxTime;

            // Encrypt the master password with a randomly generated hash
            $randomKey = Password::generateRandomBytes(32);
            $secureKey = $this->crypt->makeSecuredKey($randomKey);

            $configRequest = new ConfigRequest();
            $configRequest->add(
                self::PARAM_PASS,
                $this->crypt->encrypt($this->getMasterKeyFromContext(), $secureKey, $randomKey)
            );
            $configRequest->add(self::PARAM_KEY, $secureKey);
            $configRequest->add(self::PARAM_HASH, Hash::hashKey($randomKey));
            $configRequest->add(self::PARAM_TIME, (string)time());
            $configRequest->add(self::PARAM_MAX_TIME, (string)$this->maxTime);
            $configRequest->add(self::PARAM_ATTEMPTS, '0');

            $this->configService->saveBatch($configRequest);

            // Store the temporary key until the session ends
            $this->context->setTemporaryMasterPass($randomKey);

            $this->eventDispatcher->notify(new Event(
                'create.tempMasterPassword',
                $this,
                EventMessage::build()->addDescription(__u('Generate temporary password'))
            ));

            return $randomKey;
        } catch (Exception $e) {
            processException($e);

            throw new ServiceException(__u('Error while generating the temporary password'));
        }
    }

    /**
     * Checks whether the temporary key is valid
     *
     * @param string $key key to check
     *
     * @return bool
     * @throws ServiceException
     */
    public function checkKey(string $key): bool
    {
        try {
            $passMaxTime = (int)$this->configService->getByParam(self::PARAM_MAX_TIME);

            // Check whether the validity time or the number of attempts has been exceeded
            if ($passMaxTime === 0) {
                $this->eventDispatcher->notify(new Event(
                    'check.tempMasterPassword',
                    $this,
                    EventMessage::build()
                            ->addDescription(__u('Temporary password expired'))
                ));

                return false;
            }

            $passTime = (int)$this->configService->getByParam(self::PARAM_TIME);
            $attempts = (int)$this->configService->getByParam(self::PARAM_ATTEMPTS);

            if ($attempts >= self::MAX_ATTEMPTS
                || (!empty($passTime) && time() > $passMaxTime)
            ) {
                $this->expire();

                return false;
            }

            $isValid = Hash::checkHashKey(
                $key,
                $this->configService->getByParam(self::PARAM_HASH)
            );

            if (!$isValid) {
                $this->configService->save(self::PARAM_ATTEMPTS, (string)($attempts + 1));
            }

            return $isValid;
        } catch (NoSuchItemException) {
            return false;
        } catch (Exception $e) {
            processException($e);

            throw new ServiceException(__u('Error while checking the temporary password'));
        }
    }

    /**
     * @throws ServiceException
     */
    protected function expire(): void
    {
        $configRequest = new ConfigRequest();
        $configRequest->add(self::PARAM_PASS, '');
        $configRequest->add(self::PARAM_KEY, '');
        $configRequest->add(self::PARAM_HASH, '');
        $configRequest->add(self::PARAM_TIME, '0');
        $configRequest->add(self::PARAM_MAX_TIME, '0');
        $configRequest->add(self::PARAM_ATTEMPTS, '0');

        $this->configService->saveBatch($configRequest);

        $this->eventDispatcher->notify(new Event(
            'expire.tempMasterPassword',
            $this,
            EventMessage::build()->addDescription(__u('Temporary password expired'))
        ));
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendByEmailForGroup(int $groupId, string $key): void
    {
        $mailMessage = $this->getMessageForEmail($key);

        $emails = array_map(
            static function ($value) {
                return $value->getEmail() ?? '';
            },
            $this->userService->getUserEmailForGroup($groupId)
        );

        $this->mailService->send($mailMessage->getTitle(), $emails, $mailMessage);
    }

    private function getMessageForEmail(string $key): MailMessage
    {
        $mailMessage = new MailMessage();
        $mailMessage->setTitle(sprintf(__('%s Master Password'), AppInfoInterface::APP_NAME));
        $mailMessage->addDescription(
            __(
                'A new sysPass master password has been generated, so next time you log into the application it will be requested.'
            )
        );
        $mailMessage->addDescription(sprintf(__('The new Master Password is: %s'), $key));
        $mailMessage->addDescription(sprintf(__('This password will be valid until: %s'), date('r', $this->maxTime)));
        $mailMessage->addDescription(__('Please, don\'t forget to log in as soon as possible to save the changes.'));

        return $mailMessage;
    }

    /**
     * @throws \PHPMailer\PHPMailer\Exception
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    public function sendByEmailForAllUsers(string $key): void
    {
        $mailMessage = $this->getMessageForEmail($key);

        $emails = array_map(
            static function ($value) {
                return $value->getEmail() ?? '';
            },
            $this->userService->getUserEmailForAll()
        );

        $this->mailService->send($mailMessage->getTitle(), $emails, $mailMessage);
    }

    /**
     * Returns the master password that was encrypted with the temporary key
     *
     * @param $key string with the key used to encrypt
     *
     * @return string with the decrypted master password
     * @throws NoSuchItemException
     * @throws ServiceException
     * @throws CryptException
     */
    public function getUsingKey(string $key): string
    {
        return $this->crypt->decrypt(
            $this->configService->getByParam(self::PARAM_PASS),
            $this->configService->getByParam(self::PARAM_KEY),
            $key
        );
    }
}
