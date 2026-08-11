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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AccountGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AccountHistoryGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\AuthTokenGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\CategoryGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\ClientGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\CustomFieldGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\EventlogGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\FileGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\GridInterface;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\ItemPresetGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\NotificationGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\PluginGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\PublicLinkGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\TagGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\TrackGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserGroupGrid;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\UserProfileGrid;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the data grids behind every management listing. None of them had tests.
 *
 * A grid is the description of a listing — its identity, its columns, its row actions and its
 * pager. That description is what the templates render against, so a grid that builds an empty
 * header or drops its actions produces a listing with no columns or no buttons while every
 * endpoint around it still answers successfully. These assert each grid is fully described.
 */
#[Group('integration')]
class GridTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('gridProvider')]
    public function aGridDescribesItsListing(string $gridClass): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        /** @var GridInterface $builder */
        $builder = $container->get($gridClass);

        $grid = $builder->getGrid(QueryResult::withTotalNumRows([], 0));

        self::assertNotEmpty($grid->getId(), 'a grid needs an id for the template to target');
        self::assertNotEmpty($grid->getHeader()->getHeaders(), 'a listing with no columns shows nothing');
        self::assertNotEmpty($grid->getDataActions(), 'a listing with no actions cannot be used');
        self::assertNotNull($grid->getPager(), 'a listing without a pager cannot be paged through');
        self::assertNotEmpty($grid->getDataRowTemplate());
    }

    /**
     * Every grid is offered here, so a new one is covered by adding a line rather than a class.
     *
     * @return array<string, array{class-string}>
     */
    public static function gridProvider(): array
    {
        $grids = [
            AccountGrid::class,
            AccountHistoryGrid::class,
            AuthTokenGrid::class,
            CategoryGrid::class,
            ClientGrid::class,
            CustomFieldGrid::class,
            EventlogGrid::class,
            FileGrid::class,
            ItemPresetGrid::class,
            NotificationGrid::class,
            PluginGrid::class,
            PublicLinkGrid::class,
            TagGrid::class,
            TrackGrid::class,
            UserGrid::class,
            UserGroupGrid::class,
            UserProfileGrid::class,
        ];

        return array_combine(
            array_map(static fn(string $grid) => substr(strrchr($grid, '\\') ?: $grid, 1), $grids),
            array_map(static fn(string $grid) => [$grid], $grids)
        );
    }

    /**
     * The pager is filled in from the search that produced the listing — the page size and
     * offset come from the request, not from the grid — so a listing pages through what was
     * actually asked for.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aGridPagerTakesItsWindowFromTheSearch(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        /** @var GridInterface $builder */
        $builder = $container->get(CategoryGrid::class);

        $search = new ItemSearchDto('needle', 30, 15);

        $grid = $builder->updatePager($builder->getGrid(QueryResult::withTotalNumRows([], 42)), $search);
        $pager = $grid->getPager();

        self::assertSame(30, $pager->getLimitStart());
        self::assertSame(15, $pager->getLimitCount());
        self::assertTrue($pager->getFilterOn(), 'a search term marks the listing as filtered');
    }
}
