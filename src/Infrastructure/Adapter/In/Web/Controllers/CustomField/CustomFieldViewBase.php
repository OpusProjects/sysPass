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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\CustomField;

use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\CustomFields;
use SP\Core\Application;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SessionTimeout;
use SP\Domain\CustomField\Models\CustomFieldDefinition;
use SP\Application\CustomField\Ports\CustomFieldDefinitionService;
use SP\Application\CustomField\Ports\CustomFieldTypeService;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\Components\SelectItemAdapter;

/**
 * Class CustomFieldViewBase
 */
abstract class CustomFieldViewBase extends ControllerBase
{
    private CustomFieldDefinitionService $customFieldDefService;
    private CustomFieldTypeService       $customFieldTypeService;

    /**
     * @throws AuthException
     * @throws SessionTimeout
     */
    public function __construct(
        Application                  $application,
        WebControllerHelper          $webControllerHelper,
        CustomFieldDefinitionService $customFieldDefService,
        CustomFieldTypeService $customFieldTypeService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->customFieldDefService = $customFieldDefService;
        $this->customFieldTypeService = $customFieldTypeService;
    }

    /**
     * Sets view data for displaying custom field's data
     *
     * @param int|null $customFieldId
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    protected function setViewData(?int $customFieldId = null, bool $isView = false): void
    {
        $this->view->addTemplate('custom_field', 'itemshow');

        $customField = $customFieldId
            ? $this->customFieldDefService->getById($customFieldId)
            : new CustomFieldDefinition();

        $this->view->assign('field', $customField);
        $this->view->assign('isView', $isView);
        $this->view->assign(
            'types',
            SelectItemAdapter::factory($this->customFieldTypeService->getAll())
                ->getItemsFromModelSelected([$customField->getTypeId()])
        );
        $this->view->assign(
            'modules',
            SelectItemAdapter::factory(CustomFields::getFieldModules())
                             ->getItemsFromArraySelected([$customField->getModuleId()])
        );

        $this->view->assign('nextAction', $this->acl->getRouteFor(AclActionsInterface::ITEMS_MANAGE));

        if ($isView === true) {
            $this->view->assign('disabled', 'disabled');
            $this->view->assign('readonly', 'readonly');
        } else {
            $this->view->assign('disabled', false);
            $this->view->assign('readonly', false);
        }
    }
}
