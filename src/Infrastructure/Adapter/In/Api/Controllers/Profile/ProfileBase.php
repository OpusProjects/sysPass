<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Profile;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class ProfileBase extends ControllerBase
{
    protected UserProfileService $profileService;

    public function __construct(
        Application        $application,
        Router             $router,
        ApiService         $apiService,
        AclInterface       $acl,
        UserProfileService $profileService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->profileService = $profileService;
    }
}
