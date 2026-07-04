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

namespace SP\Tests\Infrastructure\Adapter\In\Web\Controllers\Eventlog;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Tests\IntegrationTestCase;

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
}
