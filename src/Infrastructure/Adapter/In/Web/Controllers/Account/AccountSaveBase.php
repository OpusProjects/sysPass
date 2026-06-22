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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Account;

use SP\Core\Application;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Infrastructure\Adapter\In\Web\Forms\AccountForm;
use SP\Infrastructure\Adapter\In\Web\Forms\FormInterface;
use SP\Mvc\Controller\ItemTrait;
use SP\Mvc\Controller\WebControllerHelper;

/**
 * Class AccountSaveBase
 */
abstract class AccountSaveBase extends AccountControllerBase
{
    use ItemTrait;

    protected readonly FormInterface $accountForm;

    public function __construct(
        Application                               $application,
        WebControllerHelper                       $webControllerHelper,
        protected readonly AccountService         $accountService,
        AccountPresetService                      $accountPresetService,
        protected readonly CustomFieldDataService $customFieldService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->accountForm = new AccountForm($application, $this->request, $accountPresetService);
    }
}
