<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\PublicLink;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Account\Ports\PublicLinkService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class PublicLinkBase extends ControllerBase
{
    protected PublicLinkService $publicLinkService;

    public function __construct(
        Application       $application,
        Router            $router,
        ApiService        $apiService,
        AclInterface      $acl,
        PublicLinkService $publicLinkService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->publicLinkService = $publicLinkService;
    }
}
