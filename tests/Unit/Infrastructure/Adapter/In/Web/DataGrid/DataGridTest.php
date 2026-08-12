<?php
/**
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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\DataGrid;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridAction;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGrid;
use SP\Infrastructure\Adapter\In\Web\DataGrid\DataGridData;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Layout\DataGridPager;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A grid holds the description of a listing — its rows, its actions, and which of them each row
 * gets. The grids themselves are covered by building every one of them; what the grid does with
 * what they put into it is covered here.
 *
 * The part that matters is the per-row action filter: it decides which buttons a given row is
 * offered, so a filter that lets everything through puts an action on a row that must not have it.
 */
#[Group('unitary')]
class DataGridTest extends UnitaryTestCase
{
    /**
     * A row action is only offered when the row says so. The filter is a method name resolved
     * against the row, which is how a listing shows edit on the rows the user may edit.
     *
     * @throws Exception
     */
    #[Test]
    public function aRowIsOnlyOfferedTheActionsItsFilterAllows()
    {
        $grid = $this->buildGrid();

        $grid->addDataAction($this->buildFilteredAction('always', 'isEditable'));
        $grid->addDataAction($this->buildFilteredAction('never', 'isRemovable'));

        $offered = $grid->getDataActionsFiltered(new DataGridTestRow());

        self::assertCount(1, $offered);
        self::assertSame('always', $offered[0]->getName());
    }

    /**
     * The menu actions are filtered the same way and separately, so a bulk action is not offered
     * on a row that cannot take it.
     *
     * @throws Exception
     */
    #[Test]
    public function theMenuActionsAreFilteredSeparately()
    {
        $grid = $this->buildGrid();

        $grid->addDataAction($this->buildFilteredAction('row action', 'isEditable'));
        $grid->addDataAction($this->buildFilteredAction('menu action', 'isEditable'), true);
        $grid->addDataAction($this->buildFilteredAction('hidden menu action', 'isRemovable'), true);

        $offered = $grid->getDataActionsMenuFiltered(new DataGridTestRow());

        self::assertCount(1, $offered, 'a row action is not offered in the menu');
        self::assertSame('menu action', $offered[0]->getName());
    }

    /**
     * A filter naming a method the row does not have is a wiring mistake, and is refused when it is
     * set rather than when a row is rendered.
     *
     * @throws Exception
     */
    #[Test]
    public function aFilterNamingAMethodTheRowDoesNotHaveIsRefused()
    {
        $this->expectExceptionMessage('Method does not exist');

        (new DataGridAction())->setRuntimeFilter(DataGridTestRow::class, 'noSuchMethod');
    }

    /**
     * The pager is told how many rows there are, which is what decides how many pages the listing
     * has. It is the grid's data that knows, not the search.
     *
     * @throws Exception
     */
    #[Test]
    public function updatingThePagerTakesTheRowCountFromTheData()
    {
        $data = new DataGridData();
        $data->addDataRowSource('name');
        $data->setData(QueryResult::withTotalNumRows([new Simple(['name' => 'a']), new Simple(['name' => 'b'])], 2));

        $grid = $this->buildGrid();
        $grid->setData($data);
        $grid->setPager(new DataGridPager());

        $grid->updatePager();

        self::assertSame(2, $grid->getPager()->getTotalRows());
    }

    /**
     * Every action counts towards the listing's own total, which the template uses to size the
     * actions column, and the menu keeps its own count.
     *
     * @throws Exception
     */
    #[Test]
    public function theActionsAreCountedPerPlace()
    {
        $grid = $this->buildGrid();

        $grid->addDataAction(new DataGridAction());
        $grid->addDataAction(new DataGridAction());
        $grid->addDataAction(new DataGridAction(), true);

        self::assertSame(2, $grid->getDataActionsCount());
        self::assertCount(2, $grid->getDataActions());
        self::assertCount(1, $grid->getDataActionsMenu());
    }

    /**
     * An action marked as skipped is still held, but is not counted — the template renders it
     * somewhere other than the actions column.
     *
     * @throws Exception
     */
    #[Test]
    public function aSkippedActionIsHeldButNotCounted()
    {
        $grid = $this->buildGrid();

        $grid->addDataAction((new DataGridAction())->setSkip(true));

        self::assertSame(0, $grid->getDataActionsCount());
        self::assertCount(1, $grid->getDataActions());
    }

    /**
     * The time a listing took is reported as a magnitude — a clock that went backwards between the
     * two readings must not show as a negative duration on the page.
     *
     * @throws Exception
     */
    #[Test]
    public function theElapsedTimeIsReportedAsAMagnitude()
    {
        self::assertSame(0.25, $this->buildGrid()->setTime(-0.25)->getTime());
    }

    /**
     * A template that does not exist is not set. The grid keeps rendering with whatever it already
     * had rather than pointing the include at a missing file.
     *
     * @throws Exception
     */
    #[Test]
    public function aMissingTemplateIsNotSet()
    {
        $grid = $this->buildGrid();

        $grid->setDataActionsTemplate('no-such-template');

        self::assertNull($grid->getDataActionsTemplate());
    }

    /**
     * What the listing does when it is closed — the id of the action to return to — is carried on
     * the grid.
     *
     * @throws Exception
     */
    #[Test]
    public function theActionToReturnToIsCarried()
    {
        $grid = $this->buildGrid();

        self::assertSame(0, $grid->getOnCloseAction());
        self::assertSame(60, $grid->setOnCloseAction(60)->getOnCloseAction());
    }

    /**
     * A grid is its own grid — the templates ask for it through this, so the builders can hand back
     * either one.
     *
     * @throws Exception
     */
    #[Test]
    public function aGridIsItsOwnGrid()
    {
        $grid = $this->buildGrid();

        self::assertSame($grid, $grid->getGrid());
    }

    /**
     * @throws Exception
     */
    private function buildGrid(): DataGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getViewsPath')->willReturn('/nonexistent');

        return new DataGrid($theme);
    }

    /**
     * @throws Exception
     */
    private function buildFilteredAction(string $name, string $method): DataGridAction
    {
        return (new DataGridAction())
            ->setName($name)
            ->setRuntimeFilter(DataGridTestRow::class, $method);
    }
}

/**
 * A row as the filters see it: the grid resolves the filter's method name against whatever the
 * listing put in its data.
 */
final class DataGridTestRow
{
    public function isEditable(): bool
    {
        return true;
    }

    public function isRemovable(): bool
    {
        return false;
    }
}
