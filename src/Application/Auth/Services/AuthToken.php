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

use Defuse\Crypto\Exception\CryptoException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Exception;
use SP\Application\Application;
use SP\Domain\Crypt\Hash;
use SP\Domain\Crypt\Vault;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Auth\Models\AuthTokenList as AuthTokenListModel;
use SP\Domain\Auth\Ports\AuthTokenRepository;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Common\Providers\Password;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Crypt\VaultInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\DuplicatedItemException;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Common\Dtos\QueryResult;

use function SP\__u;

/**
 * Class AuthToken
 *
 * @template T of AuthTokenModel
 * @implements AuthTokenService<T>
 */
final class AuthToken extends Service implements AuthTokenService
{
    private const SECURED_ACTIONS = [
        AclActionsInterface::ACCOUNT_VIEW_PASS,
        AclActionsInterface::ACCOUNT_EDIT_PASS,
        AclActionsInterface::ACCOUNT_CREATE,
        // Both wrap the account's own data in the link's vault, by way of
        // PublicLink::buildPublicLink() -> getSecuredLinkData(), so both need the master
        // password to be loaded onto the API context.
        AclActionsInterface::PUBLICLINK_CREATE,
        AclActionsInterface::PUBLICLINK_REFRESH,
    ];

    private const CAN_USE_SECURE_TOKEN_ACTIONS = [
        AclActionsInterface::ACCOUNT_VIEW,
        AclActionsInterface::CATEGORY_VIEW,
        AclActionsInterface::CLIENT_VIEW,
        // Sealing the master password into a new token needs the master password, and the API can
        // only get it out of the calling token's own vault. Without these two, every action a
        // token carries a vault for — ACCOUNT_VIEW and ACCOUNT_CREATE among them — could be
        // created from the web and not from the API, which answered 500 for all of them.
        //
        // It follows that a token which can mint tokens also carries the master password. That is
        // the same authority the web grants: an administrator who can reach the tokens page has
        // already unlocked the vault with their own password. It is not free, though — such a
        // token is worth as much as the vault, which is why the password protecting it is required
        // rather than optional (`AuthTokenBase::prepareSecureToken()`).
        AclActionsInterface::AUTHTOKEN_CREATE,
        AclActionsInterface::AUTHTOKEN_EDIT,
    ];

    /**
     * @param Application $application
     * @param AuthTokenRepository<AuthTokenModel> $authTokenRepository
     * @param CryptInterface $crypt
     */
    public function __construct(
        Application                          $application,
        private readonly AuthTokenRepository $authTokenRepository,
        private readonly CryptInterface      $crypt
    ) {
        parent::__construct($application);
    }

    /**
     * @param ItemSearchDto $itemSearchData
     * @return QueryResult<AuthTokenListModel>
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchDto $itemSearchData): QueryResult
    {
        return $this->authTokenRepository->search($itemSearchData);
    }

    /**
     * @param int $id
     * @return AuthTokenModel
     * @throws ConstraintException
     * @throws NoSuchItemException
     * @throws QueryException
     */
    public function getById(int $id): AuthTokenModel
    {
        $result = $this->authTokenRepository->getById($id);

        if ($result->getNumRows() === 0) {
            throw NoSuchItemException::info(__u('Token not found'));
        }

        return $result->getData(AuthTokenModel::class);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function delete(int $id): void
    {
        if ($this->authTokenRepository->delete($id)->getAffectedNumRows() === 0) {
            throw new NoSuchItemException(__u('Token not found'));
        }
    }

    /**
     * Deletes all the items for given ids
     *
     * @param int[] $ids
     *
     * @throws ServiceException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByIdBatch(array $ids): void
    {
        if ($this->authTokenRepository->deleteByIdBatch($ids)->getAffectedNumRows() === 0) {
            throw new ServiceException(__u('Error while removing the tokens'), SPException::WARNING);
        }
    }

    /**
     * @throws SPException
     * @throws CryptoException
     * @throws EnvironmentIsBrokenException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function create(AuthTokenModel $authToken): int
    {
        $secureAuthToken = $this->injectSecureData($authToken, $this->getOrBuildToken($authToken));

        return $this->authTokenRepository->create($secureAuthToken)->getLastId();
    }

    /**
     * Injects secure data for token
     *
     * @throws CryptException
     * @throws ServiceException
     */
    private function injectSecureData(AuthTokenModel $authToken, string $token): AuthTokenModel
    {
        if (self::needsSecureToken($authToken->getActionId())) {
            $properties = [
                'vault' => $this->getSecureData($token, $authToken->getHash() ?? '')->getSerialized(),
                'hash' => Hash::hashKey($authToken->getHash() ?? '')
            ];
        } else {
            $properties = [
                'hash' => null
            ];
        }

        $properties['token'] = $token;
        $properties['createdBy'] = $this->context->getUserData()->id;

        return $authToken->mutate($properties);
    }

    /**
     * Whether a token for this action carries a vault: the master password, sealed with the
     * token's own password and the token itself.
     *
     * Both lists mean that, and anything asking the administrator for a token password has to ask
     * exactly when one will be built. `AuthTokenForm` asked only for `isSecuredAction()`, so a
     * token for one of the three `CAN_USE_SECURE_TOKEN_ACTIONS` could be created with the field
     * left blank — and the vault was then sealed with the empty string. Nothing can open it:
     * `Api::getMasterPassFromVault()` reads `tokenPass` as a required parameter, which refuses the
     * empty string, so the one password that would work cannot be presented. The token was issued,
     * reported as created, and permanently unable to do the thing it was issued for.
     */
    public static function needsSecureToken(int $action): bool
    {
        return self::isSecuredAction($action) || self::canUseSecureTokenAction($action);
    }

    public static function isSecuredAction(int $action): bool
    {
        return in_array($action, self::SECURED_ACTIONS, true);
    }

    public static function canUseSecureTokenAction(int $action): bool
    {
        return in_array($action, self::CAN_USE_SECURE_TOKEN_ACTIONS, true);
    }

    /**
     * Generate the secure key for the token
     *
     * @throws ServiceException
     * @throws CryptException
     */
    private function getSecureData(string $token, string $key): VaultInterface
    {
        return Vault::factory($this->crypt)
                    ->saveData(
                        $this->getMasterKeyFromContext(),
                        $key . $token
                    );
    }

    /**
     * @param AuthTokenModel $authToken
     * @return string|null
     * @throws EnvironmentIsBrokenException
     * @throws SPException
     */
    private function getOrBuildToken(AuthTokenModel $authToken): ?string
    {
        $currentToken = $this->authTokenRepository->getTokenByUserId($authToken->getUserId());

        return match ($currentToken->getNumRows()) {
            1 => $currentToken->getData(AuthTokenModel::class)->getToken(),
            0 => $this->generateToken(),
            default => $this->generateToken(),
        };
    }

    /**
     * Generate an access token
     *
     * @throws EnvironmentIsBrokenException
     */
    private function generateToken(): string
    {
        return Password::generateRandomBytes(32);
    }

    /**
     * @throws Exception
     */
    public function refreshAndUpdate(AuthTokenModel $authToken): void
    {
        $this->authTokenRepository->transactionAware(
            function () use ($authToken) {
                $token = $this->generateToken();
                $vault = Serde::serialize($this->getSecureData($token, $authToken->getHash() ?? ''));

                $this->authTokenRepository->refreshTokenByUserId(
                    $authToken->getUserId(),
                    $token
                );
                $this->authTokenRepository->refreshVaultByUserId(
                    $authToken->getUserId(),
                    $vault,
                    Hash::hashKey($authToken->getHash() ?? '')
                );

                $secureData = $this->injectSecureData($authToken, $token);

                $this->authTokenRepository->update($secureData);
            },
            $this
        );
    }

    /**
     * @throws ConstraintException
     * @throws CryptException
     * @throws DuplicatedItemException
     * @throws EnvironmentIsBrokenException
     * @throws QueryException
     * @throws SPException
     * @throws ServiceException
     */
    public function update(AuthTokenModel $authToken): void
    {
        $secureAuthToken = $this->injectSecureData($authToken, $this->getOrBuildToken($authToken));

        // An update whose WHERE matched nothing has not updated anything, and answering the
        // caller with success for it means an edit of something that has since been deleted is
        // reported as saved. The repository already counts the rows; this is the check
        // UserProfile's own update() has always made.
        if (!$this->authTokenRepository->update($secureAuthToken)) {
            throw ServiceException::error(__u('Token not found'));
        }
    }

    /**
     * @throws SPException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function updateRaw(AuthTokenModel $authToken): void
    {
        $this->authTokenRepository->update($authToken);
    }

    /**
     * Return the data of a token
     *
     * @param int $actionId
     * @param string $token
     * @return AuthTokenModel
     * @throws NoSuchItemException
     */
    public function getTokenByToken(int $actionId, string $token): AuthTokenModel
    {
        $result = $this->authTokenRepository->getTokenByToken($actionId, $token);

        if ($result->getNumRows() === 0) {
            throw new NoSuchItemException(__u('Token not found'));
        }

        return $result->getData(AuthTokenModel::class);
    }

    /**
     * @return array<T>
     */
    public function getAll(): array
    {
        return $this->authTokenRepository->getAll()->getDataAsArray(AuthTokenModel::class);
    }
}
