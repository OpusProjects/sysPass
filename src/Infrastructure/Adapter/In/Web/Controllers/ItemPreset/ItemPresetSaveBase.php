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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\ItemPreset;

use SP\Core\Application;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Domain\ItemPreset\Models\ItemPreset as ItemPresetModel;
use SP\Infrastructure\Adapter\In\Web\Controllers\ControllerBase;
use SP\Infrastructure\Adapter\In\Web\Forms\ItemsPresetForm;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;

/**
 * Class ItemPresetSaveBase
 */
abstract class ItemPresetSaveBase extends ControllerBase
{
    /**
     * @var ItemPresetService<ItemPresetModel>
     */
    protected ItemPresetService $itemPresetService;
    protected ItemsPresetForm   $form;

    /**
     * @param ItemPresetService<ItemPresetModel> $itemPresetService
     */
    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        ItemPresetService $itemPresetService
    ) {
        parent::__construct($application, $webControllerHelper);

        $this->checkLoggedIn();

        $this->itemPresetService = $itemPresetService;
        $this->form = new ItemsPresetForm($application, $this->request);
    }
}
