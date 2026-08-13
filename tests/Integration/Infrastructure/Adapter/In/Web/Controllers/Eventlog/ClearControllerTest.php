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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Guards that clearing the event log enforces its ACL server-side. Wiping the
 * whole audit trail must reject a user without the EVENTLOG_CLEAR permission,
 * regardless of whether the grid renders the button.
 */
#[Group('integration')]
class ClearControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testClearIsDeniedWithoutAcl(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'eventlog/clear']),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * The controller never inspects how many rows the DELETE removed — it always reports the
     * same success message. This pins that clearing an already-empty log is not treated as a
     * failure (there is nothing left to distinguish "cleared" from "there was nothing to clear").
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testClearReportsSuccessEvenWhenNoRowsWereRemoved(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'eventlog/clear'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Event log cleared","data":null}');
    }

    /**
     * A database failure while clearing the log must be surfaced as an error instead of an
     * unhandled fatal.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testClearFailsWhenTheDeleteQueryErrors(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            $statement = $queryData->getQuery()->getStatement();

            if (str_contains($statement, 'EventLog')) {
                throw ConstraintException::error('Unable to clear the event log out');
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'eventlog/clear'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Unable to clear the event log out","data":null}');
    }
}
