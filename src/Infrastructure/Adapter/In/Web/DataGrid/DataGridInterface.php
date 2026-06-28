<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Adapter\In\Web\DataGrid;

use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridHeaderInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridPagerInterface;

/**
 * Interface DataGridInterface
 *
 * @package SP\Infrastructure\Adapter\In\Web\DataGrid
 */
interface DataGridInterface
{
    public function setId(string $id);

    public function getId(): string;

    public function setHeader(DataGridHeaderInterface $header);

    public function getHeader(): DataGridHeaderInterface;

    public function setData(DataGridDataInterface $data);

    public function getData(): DataGridDataInterface;

    public function addDataAction(DataGridActionInterface $action, bool $isMenu = false): DataGridInterface;

    /**
     * @return DataGridActionInterface[]
     */
    public function getDataActions(): array;

    public function getGrid(): DataGridInterface;

    public function setPager(DataGridPagerInterface $pager);

    public function getPager(): ?DataGridPagerInterface;

    public function setOnCloseAction(int $action);

    /**
     * Set the template used for the header
     */
    public function setDataHeaderTemplate(string $template);

    /**
     * Return the template used for the header
     */
    public function getDataHeaderTemplate(): string;

    /**
     * Set the template used for the actions
     */
    public function setDataActionsTemplate(string $template);

    /**
     * Return the template used for the actions
     */
    public function getDataActionsTemplate(): ?string;

    /**
     * Set the template used for the paginator
     */
    public function setDataPagerTemplate(string $template);

    /**
     * Return the template used for the paginator
     */
    public function getDataPagerTemplate(): ?string;

    /**
     * Set the template used for the query data
     */
    public function setDataRowTemplate(string $template);

    /**
     * Return the template used for the query data
     */
    public function getDataRowTemplate(): ?string;

    /**
     * Returns the total load time of the DataGrid
     */
    public function getTime(): int;

    /**
     * Sets the total load time of the DataGrid
     */
    public function setTime(int|float $time);

    /**
     * Return the actions that are shown in a menu
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsMenu(): array;

    /**
     * Return the filtered actions
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsFiltered(mixed $filter): array;

    /**
     * Return the filtered menu actions
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsMenuFiltered(mixed $filter): array;

    /**
     * Update the paginator data
     */
    public function updatePager(): DataGridInterface;
}
