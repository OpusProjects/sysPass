<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Eventlog;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Security\Ports\EventlogService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class EventlogBase extends ControllerBase
{
    protected EventlogService $eventlogService;

    public function __construct(
        Application     $application,
        Router          $router,
        ApiService      $apiService,
        AclInterface    $acl,
        EventlogService $eventlogService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->eventlogService = $eventlogService;
    }
}
