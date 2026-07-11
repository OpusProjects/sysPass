<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

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
    }
}
