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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\UserProfile;

use SP\Core\Application;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\User\Ports\UserProfileService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Forms\UserProfileForm;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class UserProfileSaveBase
 */
abstract class UserProfileSaveBase extends ControllerBase
{
    protected UserProfileService $userProfileService;
    protected CustomFieldDataService $customFieldService;
    protected UserProfileForm             $form;

    public function __construct(
        Application         $application,
        WebControllerHelper $webControllerHelper,
        UserProfileService  $userProfileService,
        CustomFieldDataService $customFieldService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->userProfileService = $userProfileService;
        $this->customFieldService = $customFieldService;
        $this->form = new UserProfileForm($application, $this->request);
    }
}
