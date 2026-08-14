<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\AuthTokenHelp;

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
}
