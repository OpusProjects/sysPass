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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ConfigGeneral;

use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Application\Application;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventMessage;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Common\Services\ServiceException;
use SP\Application\Config\Ports\ConfigBackupService;
use SP\Application\Config\Services\ConfigBackup;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\Core\Exceptions\SPException;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;

use function SP\__;
use function SP\__u;

/**
 * Class DownloadConfigBackupController
 */
final class DownloadConfigBackupController extends SimpleControllerBase
{
    public function __construct(
        Application                            $application,
        SimpleControllerHelper                 $simpleControllerHelper,
        protected readonly ConfigBackupService $configBackupService
    ) {
        parent::__construct($application, $simpleControllerHelper);
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    #[Action(ResponseType::CALLBACK)]
    public function downloadConfigBackupAction(string $type): ActionResponse
    {
        if ($this->configData->isDemoEnabled()) {
            return ActionResponse::warning(__('Ey, this is a DEMO!!'));
        }

        // Decided before it is logged, and answered rather than thrown. The event used to be
        // notified first, so a request naming a format that cannot be served — the format comes
        // from the route, so any caller can name one — left "File downloaded" in the log for a
        // download that never happened, on the one file that holds the installation's
        // configuration. The sibling backup downloads guard first and log after; this is the same
        // order, and an unservable request now gets an answer instead of a RuntimeException.
        if ($type !== 'json') {
            return ActionResponse::error(__u('File format not supported'));
        }

        $this->eventDispatcher->notify(new Event(
            'download.configBackupFile',
            $this,
            EventMessage::build(__u('File downloaded'))->addDetail(__u('File'), 'config.json')
        ));

        $data = ConfigBackup::configToJson($this->configBackupService->getBackup());

        return new ActionResponse(
            ResponseStatus::OK,
            function (ResponseService $response) use ($data) {
                $response->header('Cache-Control', 'max-age=60, must-revalidate')
                         ->header('Content-length', strlen($data))
                         ->header('Content-type', 'application/json')
                         ->header('Content-Description', ' sysPass file')
                         ->header('Content-transfer-encoding', 'binary')
                         ->header('Content-Disposition', 'attachment; filename="config.json"')
                         ->body($data);
            }
        );
    }

    /**
     * @throws SPException
     * @throws SessionTimeout
     * @throws UnauthorizedPageException
     */
    protected function initialize(): void
    {
        $this->checks();
        $this->checkAccess(AclActionsInterface::CONFIG_GENERAL);
    }
}
