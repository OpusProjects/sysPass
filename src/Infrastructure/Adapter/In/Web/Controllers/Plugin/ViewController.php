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
use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Plugin\Models\Plugin;
use SP\Domain\Plugin\Ports\PluginManagerService;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Domain\Plugin\Services\PluginManager;

use function SP\__u;
use function SP\processException;
use function SP\__;

/**
 * Class ViewController
 */
final class ViewController extends ControllerBase
{

    private PluginManagerService $pluginService;
    private PluginManager        $pluginManager;

    public function __construct(
        Application          $application,
        WebControllerHelper  $webControllerHelper,
        PluginManagerService $pluginService,
        PluginManager        $pluginManager
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->pluginService = $pluginService;
        $this->pluginManager = $pluginManager;
    }

    /**
     * View action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function viewAction(int $id): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PLUGIN_VIEW)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('View Plugin'));
            $this->view->assign('isView', true);

            $this->setViewData($id);

            $this->eventDispatcher->notify(new Event('show.plugin', $this));

            return ActionResponse::ok('', ['html' => $this->render()]);
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }

    /**
     * Sets view data for displaying items's data
     *
     * @param  int|null  $pluginId
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    protected function setViewData(?int $pluginId = null): void
    {
        $this->view->addTemplate('plugin');

        $pluginData = $pluginId
            ? $this->pluginService->getById($pluginId)
            : new Plugin();
        $pluginInfo = $this->pluginManager->getPlugin($pluginData->getName());

        $this->view->assign('plugin', $pluginData);
        $this->view->assign('pluginInfo', $pluginInfo);

        $this->view->assign('nextAction', $this->acl->getRouteFor(AclActionsInterface::ITEMS_MANAGE));

        if (true) {
            $this->view->assign('disabled', 'disabled');
            $this->view->assign('readonly', 'readonly');
        } else {
            $this->view->assign('disabled', false);
            $this->view->assign('readonly', false);
        }
    }
}
