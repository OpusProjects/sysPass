<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class AuthTokenBase extends ControllerBase
{
    protected AuthTokenService $authTokenService;

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
