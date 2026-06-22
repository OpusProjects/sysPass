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

namespace SP\Html\DataGrid\Layout;

use SP\Html\Assets\IconInterface;
use SP\Html\DataGrid\Action\DataGridActionSearch;

/**
 * Interface DataGridPagerInterface for the paginator definition
 *
 * @package SP\Html\DataGrid
 */
interface DataGridPagerInterface
{
    /**
     * Set the search field
     *
     * @param int $sortKey
     */
    public function setSortKey(int $sortKey);

    /**
     * Return the search field
     *
     * @return int
     */
    public function getSortKey(): int;

    /**
     * Set the start record of the page
     *
     * @param int $limitStart
     *
     * @return static
     */
    public function setLimitStart(int $limitStart): DataGridPagerInterface;

    /**
     * Return the start record of the page
     *
     * @return int
     */
    public function getLimitStart(): int;

    /**
     * Set the number of records on a page
     *
     * @param int $limitCount
     *
     * @return static
     */
    public function setLimitCount(int $limitCount): DataGridPagerInterface;

    /**
     * Return the number of records on a page
     *
     * @return mixed
     */
    public function getLimitCount();

    /**
     * Set the total number of records retrieved
     *
     * @param int $totalRows
     */
    public function setTotalRows(int $totalRows);

    /**
     * Return the total number of records retrieved
     *
     * @return int
     */
    public function getTotalRows(): int;

    /**
     * Set whether the filter is enabled
     *
     * @param bool $filterOn
     *
     * @return static
     */
    public function setFilterOn(bool $filterOn): DataGridPagerInterface;

    /**
     * Return whether the filter is enabled
     *
     * @return bool
     */
    public function getFilterOn(): bool;

    /**
     * Set the javascript function used for pagination
     *
     * @param string $function
     */
    public function setOnClickFunction(string $function);

    /**
     * Return the javascript function used for pagination
     *
     * @return string
     */
    public function getOnClick(): string;

    /**
     * Set the arguments for the OnClick function
     *
     * @param string $args
     */
    public function setOnClickArgs(string $args);

    /**
     * Return the function to go to the first page
     *
     * @return string
     */
    public function getOnClickFirst(): string;

    /**
     * Return the function to go to the last page
     *
     * @return string
     */
    public function getOnClickLast(): string;

    /**
     * Return the function to go to the next page
     *
     * @return string
     */
    public function getOnClickNext(): string;

    /**
     * Return the function to go to the previous page
     *
     * @return string
     */
    public function getOnClickPrev(): string;

    /**
     * @return IconInterface
     */
    public function getIconPrev(): IconInterface;

    /**
     * @param IconInterface $iconPrev
     */
    public function setIconPrev(IconInterface $iconPrev);

    /**
     * @return IconInterface
     */
    public function getIconNext(): IconInterface;

    /**
     * @param IconInterface $iconNext
     */
    public function setIconNext(IconInterface $iconNext);

    /**
     * @return IconInterface
     */
    public function getIconFirst(): IconInterface;

    /**
     * @param IconInterface $iconFirst
     */
    public function setIconFirst(IconInterface $iconFirst);

    /**
     * @return IconInterface
     */
    public function getIconLast(): IconInterface;

    /**
     * @param IconInterface $iconLast
     */
    public function setIconLast(IconInterface $iconLast);

    /**
     * @param DataGridActionSearch $sourceAction
     */
    public function setSourceAction(DataGridActionSearch $sourceAction);

    /**
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * @param int $sortOrder
     */
    public function setSortOrder(int $sortOrder);

    /**
     * @return int
     */
    public function getLast(): int;

    /**
     * @return int
     */
    public function getNext(): int;

    /**
     * @return int
     */
    public function getPrev(): int;

    /**
     * @return int
     */
    public function getFirst(): int;

    /**
     * @return DataGridActionSearch
     */
    public function getSourceAction(): DataGridActionSearch;
}
