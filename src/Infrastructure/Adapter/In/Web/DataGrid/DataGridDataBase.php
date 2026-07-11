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

use SP\Domain\Core\UI\IconInterface;
use SP\Domain\Common\Dtos\QueryResult;

/**
 * Class DataGridDataBase for setting the matrix data source
 *
 * @package SP\Infrastructure\Adapter\In\Web\DataGrid
 */
abstract class DataGridDataBase implements DataGridDataInterface
{
    /**
     * The matrix data
     *
     * @var array<int, object>
     */
    private array $data = [];
    /**
     * The columns to display from the retrieved data
     *
     * @var array<int, array{name: string, isMethod: bool, filter: ?callable, truncate: bool}>
     */
    private array $sources = [];
    /**
     * The column that identifies each element of the matrix data
     */
    private string $sourceId = '';
    /**
     * The columns to display from the retrieved data that are represented with icons
     *
     * @var array<int, array{field: string, icon: IconInterface, value: int}>
     */
    private array $sourcesWithIcon = [];
    private int $dataCount = 0;

    /**
     * @return array<int, array{field: string, icon: IconInterface, value: int}>
     */
    public function getDataRowSourcesWithIcon(): array
    {
        return $this->sourcesWithIcon;
    }

    public function addDataRowSource(
        string    $source,
        ?bool     $isMethod = false,
        ?callable $filter = null,
        ?bool     $truncate = true
    ): void {
        $this->sources[] = [
            'name' => $source,
            'isMethod' => (bool)$isMethod,
            'filter' => $filter,
            'truncate' => (bool)$truncate
        ];
    }

    public function setDataRowSourceId(string $id): void
    {
        $this->sourceId = $id;
    }

    /**
     * @return array<int, array{name: string, isMethod: bool, filter: ?callable, truncate: bool}>
     */
    public function getDataRowSources(): array
    {
        return $this->sources;
    }

    public function getDataRowSourceId(): string
    {
        return $this->sourceId;
    }

    /**
     * @return array<int, object>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @template T of object
     * @param QueryResult<T> $queryResult
     */
    public function setData(QueryResult $queryResult): void
    {
        $this->dataCount = $queryResult->getTotalNumRows();
        $this->data = $queryResult->getDataAsArray();
    }

    public function addDataRowSourceWithIcon(
        string        $source,
        IconInterface $icon,
        int           $value = 1
    ): void {
        $this->sourcesWithIcon[] = [
            'field' => $source,
            'icon' => $icon,
            'value' => $value
        ];
    }

    public function getDataCount(): int
    {
        return $this->dataCount;
    }
}
