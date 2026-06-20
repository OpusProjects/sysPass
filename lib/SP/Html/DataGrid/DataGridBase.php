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

use SP\Domain\Core\Exceptions\FileNotFoundException;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Html\DataGrid\Action\DataGridActionInterface;
use SP\Html\DataGrid\Layout\DataGridHeaderInterface;
use SP\Html\DataGrid\Layout\DataGridPagerBase;
use SP\Html\DataGrid\Layout\DataGridPagerInterface;

use function SP\__;
use function SP\logger;
use function SP\processException;

/**
 * Class DataGridBase for creating a data matrix
 *
 * @package SP\Html\DataGrid
 */
abstract class DataGridBase implements DataGridInterface
{
    /**
     * Execution time
     */
    protected int $time = 0;
    /**
     * The matrix id
     */
    protected string $id = '';
    /**
     * The matrix header
     */
    protected ?DataGridHeaderInterface $header = null;
    /**
     * The matrix data
     */
    protected ?DataGridData $data = null;
    protected ?DataGridPagerBase $pager = null;
    /**
     * The actions associated with the matrix elements
     *
     * @var DataGridActionInterface[]
     */
    protected array $actions      = [];
    protected int   $actionsCount = 0;
    /**
     * The actions associated with the matrix elements that are shown in a menu
     *
     * @var DataGridActionInterface[]
     */
    protected array $actionsMenu      = [];
    protected int   $actionsMenuCount = 0;
    /**
     * The action to perform when closing the matrix
     */
    protected int $onCloseAction = 0;
    /**
     * The template to use for rendering the header
     */
    protected ?string $headerTemplate = null;
    /**
     * The template to use for rendering the actions
     */
    protected ?string $actionsTemplate = null;
    /**
     * The template to use for rendering the paginator
     */
    protected ?string $pagerTemplate = null;
    /**
     * The template to use for rendering the data
     */
    protected ?string $rowsTemplate = null;
    /**
     * The template to use for rendering the table
     */
    protected ?string         $tableTemplate = null;
    protected ?ThemeInterface $theme         = null;

    /**
     * DataGridBase constructor.
     *
     * @param ThemeInterface $theme
     */
    public function __construct(ThemeInterface $theme)
    {
        $this->theme = $theme;
    }

    public function getOnCloseAction(): int
    {
        return $this->onCloseAction;
    }

    public function setOnCloseAction(int $action): DataGridBase
    {
        $this->onCloseAction = $action;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): DataGridBase
    {
        $this->id = $id;

        return $this;
    }

    public function getHeader(): DataGridHeaderInterface
    {
        return $this->header;
    }

    public function setHeader(DataGridHeaderInterface $header): DataGridBase
    {
        $this->header = $header;

        return $this;
    }

    public function getData(): DataGridDataInterface
    {
        return $this->data;
    }

    public function setData(DataGridDataInterface $data): DataGridBase
    {
        $this->data = $data;

        return $this;
    }

    public function addDataAction(DataGridActionInterface $action, bool $isMenu = false): DataGridInterface
    {
        if ($isMenu === false) {
            $this->actions[] = $action;

            if (!$action->isSkip()) {
                $this->actionsCount++;
            }
        } else {
            $this->actionsMenu[] = $action;

            if (!$action->isSkip()) {
                $this->actionsMenuCount++;
            }
        }

        return $this;
    }

    /**
     * @return DataGridActionInterface[]
     */
    public function getDataActions(): array
    {
        return $this->actions;
    }

    public function getGrid(): DataGridInterface
    {
        return $this;
    }

    /**
     * Set the template used for the header
     */
    public function setDataHeaderTemplate(string $template): DataGridBase
    {
        try {
            $this->headerTemplate = $this->checkTemplate($template);
        } catch (FileNotFoundException $e) {
            processException($e);
        }

        return $this;
    }

    /**
     * Check whether a template exists and return its full path
     *
     * @throws FileNotFoundException
     */
    protected function checkTemplate(string $template, ?string $base = null): string
    {
        $template = null === $base
            ? $template . '.inc'
            : $base . DIRECTORY_SEPARATOR . $template . '.inc';

        $file = $this->theme->getViewsPath() . DIRECTORY_SEPARATOR . $template;

        if (!is_readable($file)) {
            throw new FileNotFoundException(sprintf(__('Unable to retrieve "%s" template: %s'), $template, $file));
        }

        return $file;
    }

    /**
     * Return the template used for the header
     */
    public function getDataHeaderTemplate(): string
    {
        return $this->headerTemplate;
    }

    /**
     * Set the template used for the actions
     */
    public function setDataActionsTemplate(string $template): DataGridBase
    {
        try {
            $this->actionsTemplate = $this->checkTemplate($template);
        } catch (FileNotFoundException $e) {
            logger($e->getMessage());
        }

        return $this;
    }

    /**
     * Return the template used for the actions
     */
    public function getDataActionsTemplate(): ?string
    {
        return $this->actionsTemplate;
    }

    /**
     * Set the template used for the paginator
     */
    public function setDataPagerTemplate(string $template, ?string $base = null): DataGridBase
    {
        try {
            $this->pagerTemplate = $this->checkTemplate($template, $base);
        } catch (FileNotFoundException $e) {
            logger($e->getMessage());
        }

        return $this;
    }

    /**
     * Return the template used for the paginator
     */
    public function getDataPagerTemplate(): ?string
    {
        return $this->pagerTemplate;
    }

    public function setDataRowTemplate(string $template, ?string $base = null): DataGridBase
    {
        try {
            $this->rowsTemplate = $this->checkTemplate($template, $base);
        } catch (FileNotFoundException $e) {
            processException($e);
        }

        return $this;
    }

    public function getDataRowTemplate(): ?string
    {
        return $this->rowsTemplate;
    }

    /**
     * Return the paginator
     */
    public function getPager(): ?DataGridPagerInterface
    {
        return $this->pager;
    }

    /**
     * Set the paginator
     */
    public function setPager(DataGridPagerInterface $pager): DataGridBase
    {
        $this->pager = $pager;

        return $this;
    }

    /**
     * Update the paginator data
     */
    public function updatePager(): DataGridInterface
    {
        if ($this->pager instanceof DataGridPagerInterface) {
            $this->pager->setTotalRows($this->data->getDataCount());
        }

        return $this;
    }

    public function getTime(): int
    {
        return abs($this->time);
    }

    public function setTime(int|float $time): DataGridInterface
    {
        $this->time = (int)$time;

        return $this;
    }

    /**
     * Return the actions that are shown in a menu
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsMenu(): array
    {
        return $this->actionsMenu;
    }

    /**
     * Return the filtered actions
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsFiltered(mixed $filter): array
    {
        $actions = [];

        foreach ($this->actions as $action) {
            if ($action->getRuntimeFilter()($filter)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Return the filtered menu actions
     *
     * @return DataGridActionInterface[]
     */
    public function getDataActionsMenuFiltered(mixed $filter): array
    {
        $actions = [];

        foreach ($this->actionsMenu as $action) {
            if ($action->getRuntimeFilter()($filter)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public function getDataTableTemplate(): ?string
    {
        return $this->tableTemplate;
    }

    public function getDataActionsMenuCount(): int
    {
        return $this->actionsMenuCount;
    }

    public function getDataActionsCount(): int
    {
        return $this->actionsCount;
    }
}
