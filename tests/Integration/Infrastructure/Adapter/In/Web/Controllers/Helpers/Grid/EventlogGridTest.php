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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\EventlogGrid;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The event log is the one listing whose rows are rewritten before they are shown, and both
 * rewrites exist for a reason.
 *
 * On a demo instance the addresses are masked, since the log is world-readable there and every
 * entry carries the address it came from. And an entry holding a SQL statement is broken across
 * lines, because the log is where a failed query is read from and one printed as a single line is
 * unreadable.
 */
#[Group('integration')]
class EventlogGridTest extends IntegrationTestCase
{
    private bool $demo = false;

    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isDemoEnabled' => $this->demo]);
    }

    /**
     * On an ordinary instance the entry is shown as it was logged.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anEntryIsShownAsItWasLogged()
    {
        $shown = $this->whenShowing('Login from 192.168.1.50');

        self::assertStringContainsString('192.168.1.50', $shown);
    }

    /**
     * On a demo instance the address inside the entry is masked. The log is readable by anybody on
     * a demo, and every entry carries the address the action came from.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anAddressInsideAnEntryIsMaskedOnADemo()
    {
        $this->demo = true;

        $shown = $this->whenShowing('Login from 192.168.1.50');

        self::assertStringNotContainsString('192.168.1.50', $shown);
        self::assertStringContainsString('*.*.*.*', $shown);
    }

    /**
     * Every address in the entry, not only the first — an entry naming two of them would otherwise
     * leak the rest.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function everyAddressInAnEntryIsMaskedOnADemo()
    {
        $this->demo = true;

        $shown = $this->whenShowing('From 192.168.1.50 to 10.0.0.9');

        self::assertStringNotContainsString('192.168.1.50', $shown);
        self::assertStringNotContainsString('10.0.0.9', $shown);
    }

    /**
     * The address column itself is masked on a demo too, which is where the address is shown when
     * the entry's own text does not name one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theAddressColumnIsMaskedOnADemo()
    {
        $this->demo = true;

        self::assertSame('*.*.*.*', $this->transform('ipAddress', '192.168.1.50'));
    }

    /**
     * A logged SQL statement is broken across lines. The log is where a failed query is read from,
     * and one printed as a single line is unreadable.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aLoggedSqlStatementIsBrokenAcrossLines()
    {
        $shown = $this->whenShowing('SQL: SELECT id, name FROM Account WHERE id = 1');

        self::assertStringContainsString('<br>', $shown);
        self::assertStringContainsString('id,<br>', $shown, 'the selected columns are broken up');
    }

    /**
     * An entry that is not a statement keeps its own shape — the SQL rewriting must not reflow
     * ordinary text that happens to contain one of those words.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anOrdinarySentenceIsNotReflowedAsAStatement()
    {
        $shown = $this->whenShowing('Account deleted FROM the listing');

        self::assertStringNotContainsString('<br>FROM', $shown);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenShowing(string $description): string
    {
        return (string)$this->transform('description', $description);
    }

    /**
     * The listing rewrites a row's value through the transformer the grid holds for that column, so
     * the transformer is what is asked here — the same callable the template renders through.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function transform(string $column, mixed $value): mixed
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'eventlog/index'])
        );

        /** @var EventlogGrid $builder */
        $builder = $container->get(EventlogGrid::class);

        $grid = $builder->getGrid(QueryResult::withTotalNumRows([], 0));

        foreach ($grid->getData()->getDataRowSources() as $source) {
            if ($source['name'] === $column) {
                return ($source['filter'])($value);
            }
        }

        self::fail(sprintf('The listing has no %s column to rewrite', $column));
    }
}
