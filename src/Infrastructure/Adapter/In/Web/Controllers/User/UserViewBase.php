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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\User;

use SP\Core\Application;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Domain\User\Models\User;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\Components\SelectItemAdapter;

/**
 * Class UserViewBase
 */
abstract class UserViewBase extends ControllerBase
{
    use ItemTrait;

    protected UserService    $userService;
    private UserGroupService $userGroupService;
    private UserProfileService $userProfileService;
    private CustomFieldDataService $customFieldService;

    public function __construct(
        Application            $application,
        WebControllerHelper    $webControllerHelper,
        UserService        $userService,
        UserGroupService       $userGroupService,
        UserProfileService $userProfileService,
        CustomFieldDataService $customFieldService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->userService = $userService;
        $this->userGroupService = $userGroupService;
        $this->userProfileService = $userProfileService;
        $this->customFieldService = $customFieldService;
    }

    /**
     * Sets view data for displaying user's data
     *
     * @param  int|null  $userId
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     * @throws ServiceException
     */
    protected function setViewData(?int $userId = null, bool $isView = false): void
    {
        $this->view->addTemplate('user', 'itemshow');

        $user = $userId
            ? $this->userService->getById($userId)
            : new User();

        $this->view->assign('user', $user);
        $this->view->assign(
            'groups',
            SelectItemAdapter::factory($this->userGroupService->getAll())->getItemsFromModel()
        );
        $this->view->assign(
            'profiles',
            SelectItemAdapter::factory($this->userProfileService->getAll())->getItemsFromModel()
        );
        $this->view->assign('isUseSSO', $this->configData->isAuthBasicAutoLoginEnabled());
        $this->view->assign(
            'mailEnabled',
            $this->configData->isMailEnabled()
        );
        $this->view->assign(
            'nextAction',
            $this->acl->getRouteFor(AclActionsInterface::ACCESS_MANAGE)
        );

        $this->view->assign('isView', $isView);

        if ($isView === true
            || ($this->configData->isDemoEnabled()
                && $user->getLogin() === 'demo')
        ) {
            $this->view->assign('disabled', 'disabled');
            $this->view->assign('readonly', 'readonly');

            $this->view->assign(
                'usage',
                array_map(
                    static function ($value) {
                        switch ($value->ref) {
                            case 'Account':
                                $value->icon = 'description';
                                break;
                            case 'UserGroup':
                                $value->icon = 'group';
                                break;
                            case 'PublicLink':
                                $value->icon = 'link';
                                break;
                            default:
                                $value->icon = 'info_outline';
                        }

                        return $value;
                    },
                    $this->userService->getUsageForUser($userId)
                )
            );
        } else {
            $this->view->assign('disabled', false);
            $this->view->assign('readonly', false);
        }

        $this->view->assign('showViewCustomPass', $this->acl->checkUserAccess(AclActionsInterface::CUSTOMFIELD_VIEW_PASS));
        $this->view->assign(
            'customFields',
            $this->getCustomFieldsForItem(AclActionsInterface::USER, $userId, $this->customFieldService)
        );
    }
}
