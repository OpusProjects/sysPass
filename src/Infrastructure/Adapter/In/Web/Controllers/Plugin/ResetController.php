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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Plugin;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Infrastructure\Application;
use SP\Infrastructure\Events\Event;
use SP\Infrastructure\Events\EventMessage;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Plugin\Models\PluginData as PluginDataModel;
use SP\Domain\Plugin\Ports\PluginDataService;
use SP\Domain\Plugin\Ports\PluginManagerService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

use function SP\__u;
use function SP\processException;

/**
 * Class ResetController
 */
final class ResetController extends ControllerBase
{

    private PluginManagerService $pluginService;
    /**
     * @var PluginDataService<PluginDataModel>
     */
    private PluginDataService    $pluginDataService;

    /**
     * @param PluginDataService<PluginDataModel> $pluginDataService
     */
    public function __construct(
        Application          $application,
        WebControllerHelper  $webControllerHelper,
        PluginManagerService $pluginService,
        PluginDataService    $pluginDataService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->pluginService = $pluginService;
        $this->pluginDataService = $pluginDataService;
    }

    /**
     * resetAction
     *
     * @param  int  $id
     *
     * @return ActionResponse
     */
    #[Action(ResponseType::JSON)]
    public function resetAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PLUGIN_RESET)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation'));
            }

            $this->pluginDataService->delete($this->pluginService->getById($id)->getName() ?? '');

            $this->eventDispatcher->notify(new Event('edit.plugin.reset', $this, EventMessage::build()->addDescription(__u('Plugin reset'))));

            return ActionResponse::ok(__u('Plugin reset'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
