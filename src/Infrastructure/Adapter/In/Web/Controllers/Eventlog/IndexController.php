<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use SP\Application\Application;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\Security\Ports\EventlogService;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\EventlogGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class IndexController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class IndexController extends ControllerBase
{
    use ItemTrait;

    private EventlogGrid    $eventlogGrid;
    private EventlogService $eventlogService;

    public function __construct(
        Application     $application,
        WebControllerHelper $webControllerHelper,
        EventlogService $eventlogService,
        EventlogGrid    $eventlogGrid
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->eventlogService = $eventlogService;
        $this->eventlogGrid = $eventlogGrid;
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     */
    #[Action(ResponseType::PLAIN_TEXT)]
    public function indexAction(): ActionResponse
    {
        if (!$this->acl->checkUserAccess(AclActionsInterface::EVENTLOG)) {
            return ActionResponse::ok('');
        }

        $this->view->addTemplate('index');

        $this->view->assign('data', $this->getSearchGrid());

        return ActionResponse::ok($this->render());
    }

    protected function getSearchGrid(): DataGridInterface
    {
        $itemSearchData = $this->getSearchData(
            $this->configData->getAccountCount(),
            $this->request
        );

        return $this->eventlogGrid->updatePager(
            $this->eventlogGrid->getGrid($this->eventlogService->search($itemSearchData)),
            $itemSearchData
        );
    }
}
