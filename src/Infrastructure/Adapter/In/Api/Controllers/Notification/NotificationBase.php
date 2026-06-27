<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class NotificationBase extends ControllerBase
{
    protected NotificationService $notificationService;

    public function __construct(
        Application         $application,
        Router              $router,
        ApiService          $apiService,
        AclInterface        $acl,
        NotificationService $notificationService
    ) {
        parent::__construct($application, $router, $apiService, $acl);
        $this->notificationService = $notificationService;
    }
}
