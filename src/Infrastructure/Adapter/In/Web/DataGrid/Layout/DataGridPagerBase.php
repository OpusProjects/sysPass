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

namespace SP\Infrastructure\Adapter\In\Web\DataGrid\Layout;

use SP\Domain\Core\UI\IconInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionSearch;

/**
 * Class DataGridPagerBase for implementing the paginator methods
 *
 * @package SP\Infrastructure\Adapter\In\Web\DataGrid
 */
abstract class DataGridPagerBase implements DataGridPagerInterface
{
    protected int           $sortKey         = 0;
    protected int           $sortOrder       = 0;
    protected int           $limitStart      = 0;
    protected int           $limitCount      = 0;
    protected int           $totalRows       = 0;
    protected bool          $filterOn        = false;
    protected string        $onClickFunction = '';
    /**
     * @var string[]
     */
    protected array         $onClickArgs     = [];
    protected IconInterface $iconPrev;
    protected IconInterface $iconNext;
    protected IconInterface $iconFirst;
    protected IconInterface $iconLast;
    protected DataGridActionSearch $sourceAction;
    protected string        $sk;

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): DataGridPagerBase
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getIconPrev(): IconInterface
    {
        return $this->iconPrev;
    }

    public function setIconPrev(IconInterface $iconPrev): DataGridPagerBase
    {
        $this->iconPrev = $iconPrev;

        return $this;
    }

    public function getIconNext(): IconInterface
    {
        return $this->iconNext;
    }

    public function setIconNext(IconInterface $iconNext): DataGridPagerBase
    {
        $this->iconNext = $iconNext;

        return $this;
    }

    public function getIconFirst(): IconInterface
    {
        return $this->iconFirst;
    }

    public function setIconFirst(IconInterface $iconFirst): DataGridPagerBase
    {
        $this->iconFirst = $iconFirst;

        return $this;
    }

    public function getIconLast(): IconInterface
    {
        return $this->iconLast;
    }

    public function setIconLast(IconInterface $iconLast): DataGridPagerBase
    {
        $this->iconLast = $iconLast;

        return $this;
    }

    public function getSortKey(): int
    {
        return $this->sortKey;
    }

    /**
     * Set the search field
     */
    public function setSortKey(int $sortKey): DataGridPagerBase
    {
        $this->sortKey = $sortKey;

        return $this;
    }

    /**
     * Return the start record of the page
     */
    public function getLimitStart(): int
    {
        return $this->limitStart;
    }

    /**
     * Set the start record of the page
     */
    public function setLimitStart(int $limitStart): DataGridPagerBase
    {
        $this->limitStart = $limitStart;

        return $this;
    }

    /**
     * Return the number of records on a page
     */
    public function getLimitCount(): int
    {
        return $this->limitCount;
    }

    /**
     * Set the number of records on a page
     */
    public function setLimitCount(int $limitCount): DataGridPagerBase
    {
        $this->limitCount = $limitCount;

        return $this;
    }

    /**
     * Return the first page number
     */
    public function getFirstPage(): int
    {
        return $this->limitCount > 0 ? (int)ceil(($this->limitStart + 1) / $this->limitCount) : 1;
    }

    /**
     * Return the last page number
     */
    public function getLastPage(): int
    {
        return $this->limitCount > 0 ? (int)ceil($this->totalRows / $this->limitCount) : 1;
    }

    /**
     * Return the total number of records retrieved
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    /**
     * Set the total number of records retrieved
     */
    public function setTotalRows(int $totalRows): DataGridPagerBase
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    /**
     * Return whether the filter is enabled
     */
    public function getFilterOn(): bool
    {
        return $this->filterOn;
    }

    /**
     * Set whether the filter is enabled
     */
    public function setFilterOn(bool $filterOn): DataGridPagerBase
    {
        $this->filterOn = $filterOn;

        return $this;
    }

    /**
     * Set the javascript function used for pagination
     */
    public function setOnClickFunction(string $function): DataGridPagerBase
    {
        $this->onClickFunction = $function;

        return $this;
    }

    /**
     * Return the javascript function used for pagination
     */
    public function getOnClick(): string
    {
        $args = $this->parseArgs();

        return !empty($args)
            ? $this->onClickFunction . '(' . implode(',', $args) . ')'
            : $this->onClickFunction;
    }

    /**
     * @return string[]
     */
    protected function parseArgs(): array
    {
        $args = [];

        foreach ($this->onClickArgs as $arg) {
            $args[] = (!is_numeric($arg) && $arg !== 'this')
                ? '\'' . $arg . '\''
                : $arg;
        }

        return $args;
    }

    /**
     * Set the arguments for the OnClick function
     */
    public function setOnClickArgs(string $args): DataGridPagerBase
    {
        $this->onClickArgs[] = $args;

        return $this;
    }

    /**
     * Return the function to go to the first page
     */
    public function getOnClickFirst(): string
    {
        $args = $this->parseArgs();
        $args[] = $this->getFirst();

        return $this->onClickFunction . '(' . implode(',', $args) . ')';
    }

    public function getFirst(): int
    {
        return 0;
    }

    /**
     * Return the function to go to the last page
     */
    public function getOnClickLast(): string
    {
        $args = $this->parseArgs();
        $args[] = $this->getLast();

        return $this->onClickFunction . '(' . implode(',', $args) . ')';
    }

    public function getLast(): int
    {
        return (($this->totalRows % $this->limitCount) === 0)
            ? $this->totalRows - $this->limitCount
            : (int)(floor($this->totalRows / $this->limitCount) * $this->limitCount);
    }

    /**
     * Return the function to go to the next page
     */
    public function getOnClickNext(): string
    {
        $args = $this->parseArgs();
        $args[] = $this->getNext();

        return $this->onClickFunction . '(' . implode(',', $args) . ')';
    }

    public function getNext(): int
    {
        return ($this->limitStart + $this->limitCount);
    }

    /**
     * Return the function to go to the previous page
     */
    public function getOnClickPrev(): string
    {
        $args = $this->parseArgs();
        $args[] = $this->getPrev();

        return sprintf('%s(%s)', $this->onClickFunction, implode(',', $args));
    }

    public function getPrev(): int
    {
        return ($this->limitStart - $this->limitCount);
    }

    public function getSourceAction(): DataGridActionSearch
    {
        return $this->sourceAction;
    }

    public function setSourceAction(DataGridActionSearch $sourceAction): DataGridPagerBase
    {
        $this->sourceAction = $sourceAction;

        return $this;
    }
}
