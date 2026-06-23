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
use SP\Domain\Plugin\Ports\PluginManagerService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class DeleteController
 */
final class DeleteController extends ControllerBase
{
    use ItemTrait;

use function SP\__u;
use function SP\processException;

    private PluginManagerService $pluginService;

    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        PluginManagerService $pluginService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->pluginService = $pluginService;
    }

    /**
     * resetAction
     *
     * @param  int|null  $id
     *
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function deleteAction(?int $id = null): ActionResponse
    {
        try {
            if (!$this->acl->checkUserAccess(AclActionsInterface::PLUGIN_DELETE)) {
                return ActionResponse::error(__u('You don\'t have permission to do this operation')
                );
            }

            if ($id === null) {
                $this->pluginService->deleteByIdBatch($this->getItemsIdFromRequest($this->request));

                $this->eventDispatcher->notify(new Event('delete.plugin.selection', $this));

                return ActionResponse::ok(__u('Plugins deleted'));
            }

            $this->pluginService->delete($id);

            $this->eventDispatcher->notify(new Event('delete.plugin', $this));

            return ActionResponse::ok(__u('Plugin deleted'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }
}
