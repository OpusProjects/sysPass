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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Index;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Core\Context\SessionContext;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the application's landing page. It had no tests.
 *
 * Its whole job is a gate: a request that has not completed authentication is sent to the login
 * page instead of being handed the application shell.
 */
#[Group('integration')]
class IndexControllerTest extends IntegrationTestCase
{
    private bool $loggedIn = true;
    private bool $authCompleted = true;

    /**
     * Built here rather than by adjusting the inherited stub: re-stubbing a method that is
     * already configured does not reliably replace the first answer.
     *
     * @throws Exception
     */
    protected function getContext(): SessionContext|Stub
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn($this->loggedIn);
        $context->method('getAuthCompleted')->willReturn($this->authCompleted);
        $context->method('getUserData')->willReturn($this->getUserDataDto());
        $context->method('getUserProfile')->willReturn($this->getUserProfile());

        return $context;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerApplicationShell')]
    public function anAuthenticatedRequestGetsTheApplication()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A session that has signed in but not finished authenticating — the second factor, or a
     * forced password change — must not be handed the application either.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aHalfAuthenticatedRequestIsNotGivenTheApplication()
    {
        $this->authCompleted = false;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/index'])
        );

        IntegrationTestCase::runApp($container);

        // The shell is never rendered; the response is the redirect, which carries no body.
        $this->expectOutputString('');
    }

    /**
     * The landing page is the shell the whole UI hangs off, so it has to carry the container the
     * front-end fills in.
     */
    private function outputCheckerApplicationShell(string $output): void
    {
        self::assertStringContainsString('<html', $output);
        self::assertStringContainsString('id="container"', $output);
    }
}
