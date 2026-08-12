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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Http\Code;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Http\Services\Response;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The web entry point resolves a controller and an action out of the `r` parameter, which anybody
 * can put anything into.
 *
 * A name that resolves to nothing has to be answered as not found, rather than reaching the
 * dispatch and failing there — the difference between a 404 and a 500 with a stack trace on it.
 */
#[Group('integration')]
class UnknownActionTest extends IntegrationTestCase
{
    /**
     * An action that does not exist on a controller that does.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anActionThatDoesNotExistIsNotFound()
    {
        $this->whenRequesting('index/noSuchAction');
    }

    /**
     * And a controller that does not exist either.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aControllerThatDoesNotExistIsNotFound()
    {
        $this->whenRequesting('noSuchController/index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route]),
            [ResponseService::class => $this->expectsNotFound()]
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws Exception
     */
    private function expectsNotFound(): ResponseService|MockObject
    {
        $response = $this->getMockBuilder(Response::class)->onlyMethods(['code', 'append'])->getMock();

        $response->expects(self::once())->method('code')->with(Code::NOT_FOUND->value);
        $response->expects(self::once())->method('append');

        return $response;
    }
}
