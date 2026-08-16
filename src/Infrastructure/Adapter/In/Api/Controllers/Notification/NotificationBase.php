<?php

namespace SP\Infrastructure\Adapter\In\Api\Controllers\Notification;

use SP\Infrastructure\Bootstrap\Router;
use SP\Application\Application;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\NotificationHelp;

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

        $this->apiService->setHelpClass(NotificationHelp::class);
    }

    /**
     * Whether this caller may set the two flags that put a notification in front of everybody.
     *
     * `sticky` shows the notification to every user in the installation and takes it out of reach
     * of the ordinary delete, which is `WHERE id = :id AND sticky = 0`; `onlyAdmin` puts it in the
     * administrators' queue. The web form has always read both only for an application
     * administrator, and NotificationFormTest pins it — an ordinary user cannot pin one for
     * everybody. The API read them from anybody, so the same caller was refused through one door
     * and obliged through the other.
     *
     * Only the administrator check is mirrored here. The form additionally ignores the flags
     * whenever the notification names an addressee, which is a statement about what its form
     * means rather than about who may do what; carrying that across would change what an
     * administrator can already do through this endpoint, and the escalation is the missing
     * administrator check alone.
     */
    protected function mayBroadcast(): bool
    {
        // Cast: the flag is nullable on UserDto, and "not known to be an administrator" is not
        // an administrator.
        return (bool)$this->context->getUserData()->isAdminApp;
    }
}
