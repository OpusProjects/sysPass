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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\AuthToken;

use SP\Application\Application;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Auth\Models\AuthToken as AuthTokenModel;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\CustomField\Models\CustomFieldData as CustomFieldDataModel;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Forms\AuthTokenForm;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemTrait;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * A base class for all "save" actions
 */
abstract class AuthTokenSaveBase extends ControllerBase
{
    use ItemTrait;

    protected readonly AuthTokenForm $form;

    /**
     * @param AuthTokenService<AuthTokenModel> $authTokenService
     * @param CustomFieldDataService<CustomFieldDataModel> $customFieldService
     * @throws AuthException
     * @throws SessionTimeout
     */
    public function __construct(
        Application                               $application,
        WebControllerHelper                       $webControllerHelper,
        protected readonly AuthTokenService       $authTokenService,
        protected readonly CustomFieldDataService $customFieldService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->form = new AuthTokenForm($application, $this->request);
    }
}
