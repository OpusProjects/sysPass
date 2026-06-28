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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\SecurityManager;

use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Application\Security\Ports\EventlogService;
use SP\Application\Security\Ports\TrackService;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridTab;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\EventlogGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\TrackGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\TabsGridHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class IndexController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class IndexController extends ControllerBase
{
    protected ItemSearchDto  $itemSearchData;
    protected TabsGridHelper $tabsGridHelper;
    private EventlogGrid             $eventlogGrid;
    private TrackGrid             $trackGrid;
    private EventlogService $eventlogService;
    private TrackService    $trackService;

    public function __construct(
        Application         $application,
        WebControllerHelper $webControllerHelper,
        TabsGridHelper      $tabsGridHelper,
        EventlogGrid        $eventlogGrid,
        TrackGrid           $trackGrid,
        EventlogService     $eventlogService,
        TrackService $trackService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->tabsGridHelper = $tabsGridHelper;
        $this->eventlogGrid = $eventlogGrid;
        $this->trackGrid = $trackGrid;
        $this->eventlogService = $eventlogService;
        $this->trackService = $trackService;

        $this->itemSearchData = new ItemSearchDto(limitCount: $this->configData->getAccountCount());
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    #[Action(ResponseType::PLAIN_TEXT)]
    public function indexAction(): ActionResponse
    {
        $this->getGridTabs();

        return ActionResponse::ok($this->render());
    }

    /**
     * Returns a tabbed grid with items
     *
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getGridTabs(): void
    {
        if ($this->checkAccess(AclActionsInterface::EVENTLOG)
            && $this->configData->isLogEnabled()
        ) {
            $this->tabsGridHelper->addTab($this->getEventlogList());
        }

        if ($this->checkAccess(AclActionsInterface::TRACK)) {
            $this->tabsGridHelper->addTab($this->getTracksList());
        }

        $this->eventDispatcher->notify(new Event('show.itemlist.security', $this)
        );

        $this->tabsGridHelper->renderTabs(
            $this->acl->getRouteFor(AclActionsInterface::SECURITY_MANAGE),
            $this->request->analyzeInt('tabIndex', 0)
        );
    }

    /**
     * Returns eventlog data tab
     *
     * @return DataGridTab
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getEventlogList(): DataGridTab
    {
        return $this->eventlogGrid->getGrid($this->eventlogService->search($this->itemSearchData))->updatePager();
    }

    /**
     * Returns tracks data tab
     *
     * @return DataGridTab
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getTracksList(): DataGridTab
    {
        return $this->trackGrid->getGrid($this->trackService->search($this->itemSearchData))->updatePager();
    }

    /**
     * @return TabsGridHelper
     */
    public function getTabsGridHelper(): TabsGridHelper
    {
        return $this->tabsGridHelper;
    }
}
