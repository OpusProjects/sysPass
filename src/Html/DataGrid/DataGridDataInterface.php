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

namespace SP\Html\DataGrid;

use SP\Domain\Core\UI\IconInterface;
use SP\Infrastructure\Database\QueryResult;

/**
 * Interface DataGridDataInterface
 *
 * @package SP\Html\DataGrid
 */
interface DataGridDataInterface
{
    /**
     * Set the query data sources
     */
    public function addDataRowSource(
        string   $source,
        bool     $isMethod = false,
        ?callable $filter = null,
        bool     $truncate = true
    ): void;

    /**
     * Return the query data sources
     */
    public function getDataRowSources(): array;

    /**
     * Set the data source used as the elements' Id
     */
    public function setDataRowSourceId(string $id): void;

    /**
     * Return the data source used as the elements' Id
     */
    public function getDataRowSourceId(): string;

    /**
     * Set the query data
     */
    public function setData(QueryResult $queryResult): void;

    /**
     * Return the query data
     */
    public function getData(): array;

    /**
     * Set the data sources that are shown with icons
     */
    public function addDataRowSourceWithIcon(
        string        $source,
        IconInterface $icon,
        int           $value = 1
    ): void;

    /**
     * Return the data sources that are shown with icons
     */
    public function getDataRowSourcesWithIcon(): array;

    /**
     * Return the number of elements retrieved
     */
    public function getDataCount(): int;
}
