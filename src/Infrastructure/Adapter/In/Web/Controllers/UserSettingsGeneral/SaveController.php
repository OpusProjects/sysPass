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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserSettingsGeneral;

use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseType;

use Exception;
use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\UserPreferences;
use SP\Application\User\Ports\UserService;
use SP\Application\User\Services\User;
use SP\Infrastructure\Adapter\In\Web\Controllers\SimpleControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;

use function SP\__u;
use function SP\processException;

/**
 * Class SaveController
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers
 */
final class SaveController extends SimpleControllerBase
{

    private User $userService;

    public function __construct(
        Application $application,
        SimpleControllerHelper $simpleControllerHelper,
        UserService $userService
    ) {
        parent::__construct($application, $simpleControllerHelper);

        $this->checks();

        $this->userService = $userService;
    }

    /**
     * @return bool
     * @throws JsonException
     */
    #[Action(ResponseType::JSON)]
    public function saveAction(): ActionResponse
    {
        try {
            $userData = $this->session->getUserData();

            $userPreferencesData = $this->getUserPreferencesData($userData);

            $this->userService->updatePreferencesById($userData->getId(), $userPreferencesData);

            // Save preferences in current session
            $userData->setPreferences($userPreferencesData);

            return ActionResponse::ok(__u('Preferences updated'));
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notify(new Event('exception', $e));

            return ActionResponse::error($e->getMessage());
        }
    }

    /**
     * @param UserDto $userData
     *
     * @return UserPreferences
     */
    private function getUserPreferencesData(UserDto $userData): UserPreferences
    {
        $userPreferencesData = clone $userData->getPreferences();

        $userPreferencesData->setUserId($userData->getId());
        $userPreferencesData->setLang($this->request->analyzeString('userlang'));
        $userPreferencesData->setTheme($this->request->analyzeString('usertheme', 'material-blue'));
        $userPreferencesData->setResultsPerPage($this->request->analyzeInt('resultsperpage', 12));
        $userPreferencesData->setAccountLink($this->request->analyzeBool('account_link', false));
        $userPreferencesData->setSortViews($this->request->analyzeBool('sort_views', false));
        $userPreferencesData->setTopNavbar($this->request->analyzeBool('top_navbar', false));
        $userPreferencesData->setOptionalActions($this->request->analyzeBool('optional_actions', false));
        $userPreferencesData->setResultsAsCards($this->request->analyzeBool('resultsascards', false));
        $userPreferencesData->setCheckNotifications($this->request->analyzeBool('check_notifications', false));
        $userPreferencesData->setShowAccountSearchFilters(
            $this->request->analyzeBool('show_account_search_filters', false)
        );

        return $userPreferencesData;
    }
}
