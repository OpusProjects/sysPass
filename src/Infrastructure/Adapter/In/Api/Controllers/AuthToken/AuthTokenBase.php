<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Common\Services\ServiceException;
use SP\Application\Auth\Services\AuthToken;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\AuthTokenHelp;

use function SP\__u;

abstract class AuthTokenBase extends ControllerBase
{
    /**
     * @var AuthTokenService<AuthTokenModel>
     */
    protected AuthTokenService $authTokenService;

    /**
     * @param AuthTokenService<AuthTokenModel> $authTokenService
     */
    public function __construct(
        Application      $application,
        Router           $router,
        ApiService       $apiService,
        AclInterface     $acl,
        AuthTokenService $authTokenService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->authTokenService = $authTokenService;

        $this->apiService->setHelpClass(AuthTokenHelp::class);
    }

    /**
     * A row from a listing, without the secrets on it. The permission to search authorisations is
     * coarser than the permission to view one: a caller holding only the first would otherwise
     * harvest every bearer token in the installation in a single call.
     *
     * @return array<string, mixed>
     */
    protected static function withoutSecrets(AuthTokenModel $authToken): array
    {
        return $authToken->toArray(null, AuthTokenModel::SECRET_COLS, true);
    }

    /**
     * What a token that carries a vault needs before it can be written.
     *
     * A vault is the master password sealed with the token's own password and the token itself, so
     * two things have to be true and neither was checked here. The password cannot be blank, or the
     * vault is sealed with the empty string and nothing can open it again — `Api` reads `tokenPass`
     * as a required parameter, and required refuses the empty string, so the one password that
     * would work cannot be presented. And the master password has to be on the context to seal it
     * there at all, which on the API means loading it from the calling token's own vault.
     *
     * Without the second, this endpoint answered 500 "Error while retrieving master password from
     * context" for every action a token carries a vault for, with or without a password, while the
     * web created the same tokens without difficulty.
     *
     * @throws ServiceException
     * @throws ValidationException
     */
    final protected function prepareSecureToken(int $actionId, ?string $password): void
    {
        if (!AuthToken::needsSecureToken($actionId)) {
            return;
        }

        if (empty($password)) {
            throw ValidationException::error(__u('Password cannot be blank'));
        }

        $this->apiService->requireMasterPass();
    }
}
