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

namespace SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid;

use SP\Application\Application;
use SP\Domain\Common\Models\Model;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Infrastructure\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionSearch;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridData;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridHeader;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridPager;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\HelperBase;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;

/**
 * Class GridBase
 *
 * @package SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid
 *
 * @template T of Model&object
 * @implements GridInterface<T>
 */
abstract class GridBase extends HelperBase implements GridInterface
{
    protected float               $queryTimeStart;
    protected ThemeIconsInterface $icons;

    public function __construct(
        Application                       $application,
        TemplateInterface                 $template,
        RequestService                    $request,
        protected readonly AclInterface   $acl,
        protected readonly ThemeInterface $theme
    ) {
        parent::__construct($application, $template, $request);

        $this->queryTimeStart = microtime(true);
        $this->icons = $this->theme->getIcons();
    }


    /**
     * Update the pager data
     *
     * @param DataGridInterface $dataGrid
     * @param ItemSearchDto $itemSearchData
     *
     * @return DataGridInterface
     */
    public function updatePager(
        DataGridInterface $dataGrid,
        ItemSearchDto $itemSearchData
    ): DataGridInterface {
        $dataGrid->getPager()
                 ->setLimitStart($itemSearchData->getLimitStart())
                 ->setLimitCount($itemSearchData->getLimitCount())
                 ->setFilterOn(!empty($itemSearchData->getSearchString()));

        $dataGrid->updatePager();

        return $dataGrid;
    }

    /**
     * Return the default pager
     *
     * @param DataGridActionSearch $sourceAction
     *
     * @return DataGridPager
     */
    final protected function getPager(
        DataGridActionSearch $sourceAction
    ): DataGridPager {
        $gridPager = new DataGridPager();
        $gridPager->setSourceAction($sourceAction);
        $gridPager->setOnClickFunction('appMgmt/nav');
        $gridPager->setLimitStart(0);
        $gridPager->setLimitCount($this->configData->getAccountCount());
        $gridPager->setIconPrev($this->icons->navPrev());
        $gridPager->setIconNext($this->icons->navNext());
        $gridPager->setIconFirst($this->icons->navFirst());
        $gridPager->setIconLast($this->icons->navLast());

        return $gridPager;
    }

    /**
     * @return DataGridInterface
     */
    abstract protected function getGridLayout(): DataGridInterface;

    /**
     * @return DataGridHeader
     */
    abstract protected function getHeader(): DataGridHeader;

    /**
     * @return DataGridData
     */
    abstract protected function getData(): DataGridData;
}
