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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use SP\Application\Application;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Application\User\Ports\UserGroupService;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserGroupGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use function SP\__u;

/**
 * Class SearchController
 */
final class SearchController extends ControllerBase
{
    use ItemTrait;


    /**
     * @var UserGroupService<UserGroupModel>
     */
    private UserGroupService $userGroupService;
    private UserGroupGrid    $userGroupGrid;

    /**
     * @param UserGroupService<UserGroupModel> $userGroupService
     */
    public function __construct(
        Application      $application,
        WebControllerHelper $webControllerHelper,
        UserGroupService $userGroupService,
        UserGroupGrid    $userGroupGrid
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->userGroupService = $userGroupService;
        $this->userGroupGrid = $userGroupGrid;
    }

    /**
     * Search action
     *
     * @return ActionResponse
     * @throws ConstraintException
     * @throws QueryException
     */
    #[Action(ResponseType::JSON)]
    public function searchAction(): ActionResponse
    {
        if (!$this->acl->checkUserAccess(AclActionsInterface::GROUP_SEARCH)) {
            return ActionResponse::error(__u('You don\'t have permission to do this operation'));
        }

        $this->view->addTemplate('datagrid-table', 'grid');
        $this->view->assign('index', $this->request->analyzeInt('activetab', 0));
        $this->view->assign('data', $this->getSearchGrid());

        return ActionResponse::ok('', ['html' => $this->render()]);
    }

    /**
     * getSearchGrid
     *
     * @return DataGridInterface
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getSearchGrid(): DataGridInterface
    {
        $itemSearchData = $this->getSearchData($this->configData->getAccountCount(), $this->request);

        return $this->userGroupGrid->updatePager(
            $this->userGroupGrid->getGrid($this->userGroupService->search($itemSearchData)),
            $itemSearchData
        );
    }
}
