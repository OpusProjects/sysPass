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
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\TrackGrid;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The track listing is the one showing blocked sign-in attempts, and its address column is the
 * same kind of sensitive data the event log masks on a demo instance — an IPv4/IPv6 address that
 * identifies where a failed login came from.
 *
 * The other thing worth pinning down is the Unlock action: it carries a consequence (clearing a
 * block early), so it must only be offered on a row that is actually still blocking. The row
 * template (grid/datagrid-rows.inc) hides an action whenever the row's field equals the
 * configured filter value, so "filter on tracked=0" reads backwards until that is spelled out:
 * it hides Unlock on rows where tracked is 0 (not currently blocking) and shows it otherwise.
 */
#[Group('integration')]
class TrackGridTest extends IntegrationTestCase
{
    private bool $demo = false;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isDemoEnabled' => $this->demo]);
    }

    /**
     * On an ordinary instance the address is shown as it was recorded.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('addressColumnProvider')]
    public function anAddressIsShownAsRecordedWhenNotOnADemo(string $column, string $address): void
    {
        $shown = $this->transform($column, inet_pton($address));

        self::assertSame($address, $shown);
    }

    /**
     * On a demo instance the address is masked — the track listing is reachable by anybody who
     * can sign in, and every blocked attempt carries the address it came from.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('addressColumnProvider')]
    public function anAddressIsMaskedOnADemo(string $column, string $address): void
    {
        $this->demo = true;

        self::assertSame('*.*.*.*', $this->transform($column, inet_pton($address)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function addressColumnProvider(): array
    {
        return [
            'ipv4' => ['ipv4', '192.168.1.50'],
            'ipv6' => ['ipv6', '2001:db8::1'],
        ];
    }

    /**
     * A track that was never resolved for this protocol (e.g. an IPv6-only client has no ipv4
     * value) renders as blank rather than as an empty or malformed cell.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anUnrecordedAddressIsShownAsBlank(): void
    {
        self::assertSame('&nbsp;', $this->transform('ipv4', null));
        self::assertSame('&nbsp;', $this->transform('ipv6', null));
    }

    /**
     * Unlock is a consequential action -- it clears a block before it would otherwise expire --
     * so it must be scoped to rows that are still blocking (tracked != 0), not offered on every
     * row regardless of state.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function unlockIsScopedToRowsStillBlocking(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'track/search'])
        );

        /** @var TrackGrid $builder */
        $builder = $container->get(TrackGrid::class);

        $grid = $builder->getGrid(QueryResult::withTotalNumRows([], 0));

        $unlockAction = null;

        foreach ($grid->getDataActions() as $action) {
            if ($action->getTitle() === 'Unlock Track') {
                $unlockAction = $action;
            }
        }

        self::assertNotNull($unlockAction, 'the listing offers no way to unlock a blocked attempt');
        self::assertSame(
            [['field' => 'tracked', 'value' => 0]],
            $unlockAction->getFilterRowSource(),
            'the row template hides an action when the row value matches, so this must hide '
            . 'Unlock on rows that are not currently blocking (tracked=0)'
        );
    }

    /**
     * The listing rewrites a row's value through the transformer the grid holds for that column,
     * so the transformer is what is asked here -- the same callable the template renders through.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function transform(string $column, mixed $value): mixed
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'track/search'])
        );

        /** @var TrackGrid $builder */
        $builder = $container->get(TrackGrid::class);

        $grid = $builder->getGrid(QueryResult::withTotalNumRows([], 0));

        foreach ($grid->getData()->getDataRowSources() as $source) {
            if ($source['name'] === $column) {
                return ($source['filter'])($value);
            }
        }

        self::fail(sprintf('The listing has no %s column to rewrite', $column));
    }
}
