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

namespace SP\Application\Api\Services;

use SP\Domain\Api\Services\ApiStatuses;
use SP\Domain\Http\Code;

use Exception;
use SP\Application\Application;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Crypt\Hash;
use SP\Domain\Crypt\Vault;
use SP\Application\Api\Ports\ApiRequestService;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Application\Auth\Services\AuthToken;
use SP\Domain\Common\Providers\Filter;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\VaultInterface;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Application\Security\Ports\TrackService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Api\Ports\HelpInterface;

use function SP\__u;
use function SP\logger;
use function SP\processException;

/**
 * Class Api
 */
final class Api extends Service implements ApiService
{
    private TrackRequest    $trackRequest;
    private ?AuthTokenModel $authToken = null;
    private ?string         $helpClass = null;
    private ?ApiStatuses $status = null;

    /**
     * @param AuthTokenService<AuthTokenModel> $authTokenService
     * @throws InvalidArgumentException
     */
    public function __construct(
        Application                        $application,
        private readonly TrackService      $trackService,
        private readonly ApiRequestService $apiRequest,
        private readonly AuthTokenService  $authTokenService,
        private readonly UserService       $userService,
        private readonly UserProfileService $userProfileService
    ) {
        parent::__construct($application);

        $this->trackRequest = $trackService->buildTrackRequest(__CLASS__);
    }

    /**
     * Sets up API
     *
     * @throws ServiceException
     * @throws SPException
     * @throws Exception
     */
    public function setup(int $actionId): void
    {
        $this->status = ApiStatuses::INITIALIZING;

        if ($this->trackService->checkTracking($this->trackRequest)) {
            $this->addTracking();

            throw new ServiceException(
                __u('Attempts exceeded'),
                SPException::ERROR,
                null,
                Code::INTERNAL_SERVER_ERROR->value
            );
        }

        try {
            $token = $this->getParam('authToken');

            if ($token === null) {
                $this->accessDenied();
            }

            $this->authToken = $this->authTokenService
                ->getTokenByToken($actionId, $token);
        } catch (NoSuchItemException $e) {
            logger($e->getMessage(), 'ERROR');

            // A token that does not exist is the attempt this counter is for. Guessing token
            // values is the only way in from outside, and it was the single failure that did not
            // count: a request that omitted the token entirely did, which is not what anybody
            // brute-forcing sends. The limit above was therefore unreachable by the attack it
            // guards against.
            $this->addTracking();

            // For security reasons there won't be any hint about a not found token...
            throw new ServiceException(
                __u('Internal error'),
                SPException::ERROR,
                null,
                Code::INTERNAL_SERVER_ERROR->value
            );
        }

        if ($this->authToken->getActionId() !== $actionId) {
            $this->accessDenied();
        }

        $this->setupUser();

        if (AuthToken::isSecuredAction($actionId)) {
            $this->requireMasterPass();
        }

        $this->status = ApiStatuses::INITIALIZED;
    }

    /**
     * Add a tracking entry
     *
     * @throws ServiceException
     */
    private function addTracking(): void
    {
        try {
            $this->trackService->add($this->trackRequest);
        } catch (Exception $e) {
            processException($e);

            throw new ServiceException(
                __u('Internal error'),
                SPException::ERROR,
                null,
                Code::INTERNAL_SERVER_ERROR->value
            );
        }
    }

    /**
     * Return the value of a parameter
     *
     * @param string $param
     * @param bool $required Whether it is required
     * @param mixed|null $default Default value
     *
     * @return mixed
     * @throws ServiceException
     */
    public function getParam(string $param, bool $required = false, mixed $default = null): mixed
    {
        // "Required" used to mean only that the key was present, so `{"name": ""}` satisfied it and
        // the API created rows the web forms refuse — a category with no name is accepted here and
        // rejected there by CategoryForm::checkCommon(), and the same holds for every entity, since
        // every required parameter in the API comes through this one method.
        //
        // Null and the empty string are the two ways a value can be supplied and say nothing. They
        // are refused; anything else a caller sends is still its own concern, including `0` and
        // `false`, which are meaningful values rather than absent ones.
        $value = $this->apiRequest->get($param, $default);

        if ($required
            && (!$this->apiRequest->exists($param) || in_array($value, [null, ''], true))
        ) {
            throw new ServiceException(
                __u('Wrong parameters'),
                SPException::ERROR,
                $this->getHelpHint($this->apiRequest->getMethod()),
                Code::BAD_REQUEST->value
            );
        }

        return $value;
    }

    /**
     * Return the help for an action
     *
     * @param string $action
     *
     * @return array<string, mixed>
     */
    private function getHelp(string $action): array
    {
        if ($this->helpClass !== null) {
            return $this->helpClass::getHelpFor($action);
        }

        return [];
    }

    /**
     * Return the help for an action as a string hint
     *
     * @param string $action
     *
     * @return string
     */
    private function getHelpHint(string $action): string
    {
        $help = $this->getHelp($action);

        return empty($help) ? '' : (string)json_encode($help);
    }

    /**
     * @throws ServiceException
     */
    private function accessDenied(): void
    {
        $this->addTracking();

        throw new ServiceException(
            __u('Unauthorized access'),
            SPException::ERROR,
            null,
            401
        );
    }

    /**
     * Sets up user's data in context and performs some user checks
     *
     * @throws SPException
     */
    private function setupUser(): void
    {
        $userDto = UserDto::fromModel($this->userService->getById($this->authToken->getUserId() ?? 0));
        $userDto->isDisabled && $this->accessDenied();

        $this->context->setUserData($userDto);
        $this->context->setUserProfile(
            $this->userProfileService
                ->getById($userDto->userProfileId ?? 0)
                ->hydrate(ProfileData::class) ?? new ProfileData()
        );
    }

    /**
     * @throws ContextException
     * @throws ServiceException
     */
    public function requireMasterPass(): void
    {
        $this->context->setTrasientKey(Context::MASTER_PASSWORD_KEY, $this->getMasterPassFromVault());
    }

    /**
     * Return the master password
     *
     * @throws ServiceException
     */
    private function getMasterPassFromVault(): string
    {
        $this->requireInitialized();

        try {
            $tokenPass = $this->getParam('tokenPass', true);

            Hash::checkHashKey($tokenPass, $this->authToken->getHash() ?? '') || $this->accessDenied();

            $vaultData = $this->authToken->getVault();

            if ($vaultData === null) {
                throw new ServiceException(
                    __u('Internal error'),
                    SPException::ERROR,
                    __u('Invalid data'),
                    Code::INTERNAL_SERVER_ERROR->value
                );
            }

            /** @var VaultInterface $vault */
            $vault = unserialize($vaultData, ['allowed_classes' => [Vault::class, \SP\Infrastructure\Crypt\Crypt::class]]);

            $key = $tokenPass . $this->getParam('authToken');

            if ($vault && ($pass = $vault->getData($key))) {
                return $pass;
            }

            throw new ServiceException(
                __u('Internal error'),
                SPException::ERROR,
                __u('Invalid data'),
                Code::INTERNAL_SERVER_ERROR->value
            );
        } catch (CryptException $e) {
            throw new ServiceException(
                __u('Internal error'),
                SPException::ERROR,
                $e->getMessage(),
                Code::INTERNAL_SERVER_ERROR->value
            );
        }
    }

    /**
     * @throws ServiceException
     */
    private function requireInitialized(): void
    {
        if ($this->status === null) {
            throw new ServiceException(
                __u('API not initialized'),
                SPException::ERROR,
                __u('Please run setup method before'),
                Code::INTERNAL_SERVER_ERROR->value
            );
        }
    }

    /**
     * A parameter the caller sent with the wrong JSON type is a bad request, not a crash.
     *
     * The readers below hand their value straight to a typed function — `Filter::getInt(int|string)`,
     * `Filter::getString(?string)`, or `getParamRaw()`'s own `?string` return — so a JSON body
     * carrying `{"name": 123}`, `{"userGroupId": true}` or an array where a scalar belongs raised a
     * `TypeError` that escaped as a 500 carrying the class, the method and the server's absolute
     * path. Every string and integer parameter on every endpoint could be made to do it, and the
     * same value sent through a query string works, because everything arrives as a string there.
     *
     * `getParamArray()` already answered this correctly, and this is its refusal, shared: the type
     * has to be the one the endpoint declares. Nothing is coerced — converting silently is how
     * `1.5` becomes the id `15` (`FILTER_SANITIZE_NUMBER_INT` drops the point) and how a boolean
     * becomes somebody's name.
     */
    private function wrongParameterType(): ServiceException
    {
        return new ServiceException(
            __u('Wrong parameters'),
            SPException::ERROR,
            $this->getHelpHint($this->apiRequest->getMethod()),
            Code::BAD_REQUEST->value
        );
    }

    /**
     * @throws ServiceException
     */
    public function getParamInt(string $param, bool $required = false, $default = null): ?int
    {
        $value = $this->getParam($param, $required, $default);

        if (null !== $value) {
            if (!is_int($value) && !is_string($value)) {
                throw $this->wrongParameterType();
            }

            return Filter::getInt($value);
        }

        return $default;
    }

    /**
     * @throws ServiceException
     */
    public function getParamString(string $param, bool $required = false, $default = null): ?string
    {
        $value = $this->getParam($param, $required, $default);

        if (null !== $value) {
            if (!is_string($value)) {
                throw $this->wrongParameterType();
            }

            return Filter::getString($value);
        }

        return $default;
    }

    /**
     * @return array<int|string, int|float|string|null>|null
     * @throws ServiceException
     */
    public function getParamArray(string $param, bool $required = false, $default = null): ?array
    {
        $value = $this->getParam($param, $required, $default);

        if (null !== $value) {
            if (!is_array($value)) {
                throw $this->wrongParameterType();
            }

            return Filter::getArray($value);
        }

        return null;
    }

    /**
     * @throws ServiceException
     */
    public function getParamRaw(string $param, bool $required = false, $default = null): ?string
    {
        $value = $this->getParam($param, $required, $default);

        if (null !== $value) {
            if (!is_string($value)) {
                throw $this->wrongParameterType();
            }

            return $value;
        }

        return $default;
    }

    /**
     * @return string
     * @throws CryptException
     * @throws ServiceException
     */
    public function getMasterPass(): string
    {
        $this->requireInitialized();

        return $this->getMasterKeyFromContext();
    }

    public function getRequestId(): int
    {
        return $this->apiRequest->getId();
    }

    /**
     * @throws InvalidClassException
     */
    public function setHelpClass(string $helpClass): void
    {
        if (class_exists($helpClass) && is_subclass_of($helpClass, HelpInterface::class)) {
            $this->helpClass = $helpClass;

            return;
        }

        throw new InvalidClassException('Invalid class for helper');
    }
}
