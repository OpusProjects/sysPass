<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Core\Bootstrap\Router;
use SP\Core\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;

abstract class NotificationBase extends ControllerBase
{
    /**
     * @var NotificationService<NotificationModel>
     */
    protected NotificationService $notificationService;

    /**
     * @param NotificationService<NotificationModel> $notificationService
     */
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
