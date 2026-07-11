<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\User;

use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class UserBase extends ControllerBase
{
    protected UserService $userService;

    public function __construct(
        Application  $application,
        Router       $router,
        ApiService   $apiService,
        AclInterface $acl,
        UserService  $userService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->userService = $userService;
    }
}
