<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Notification;

use SP\Core\Application;
use SP\Application\Notification\Ports\NotificationService;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Forms\NotificationForm;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class NotificationSaveBase
 */
abstract class NotificationSaveBase extends ControllerBase
{
    /**
     * @var NotificationService<NotificationModel>
     */
    protected NotificationService $notificationService;
    protected NotificationForm    $form;

    /**
     * @param NotificationService<NotificationModel> $notificationService
     */
    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        NotificationService $notificationService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->notificationService = $notificationService;
        $this->form = new NotificationForm($application, $this->request);
    }
}
