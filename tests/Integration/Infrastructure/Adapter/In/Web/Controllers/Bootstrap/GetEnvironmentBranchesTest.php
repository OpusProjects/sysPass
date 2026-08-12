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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Bootstrap;

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
 * The environment endpoint is what the front-end configures itself from, and most of what it
 * answers is conditional. The existing test covers the shape of the payload; these cover the
 * conditions, which is where a wrong answer changes what the UI does.
 */
#[Group('integration')]
class GetEnvironmentBranchesTest extends IntegrationTestCase
{
    private bool $loggedIn = true;

    protected function getContext(): SessionContext|Stub
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn($this->loggedIn);
        $context->method('getAuthCompleted')->willReturn(true);
        $context->method('getUserData')->willReturn($this->getUserDataDto());
        $context->method('getUserProfile')->willReturn($this->getUserProfile());

        return $context;
    }

    /**
     * A signed-out browser is told not to poll for notifications — there is nobody to have any,
     * and the poll would be an unauthenticated request on a timer.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotificationsOff')]
    public function aSignedOutBrowserIsNotToldToPollForNotifications()
    {
        $this->loggedIn = false;

        $this->whenAsking();
    }

    /**
     * A signed-in one is, so the UI can show them.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotificationsOn')]
    public function aSignedInBrowserIsToldToPollForNotifications()
    {
        $this->whenAsking();
    }

    /**
     * The update and notice checks are only offered to somebody who could act on them, so a
     * plain user is not told to poll a remote service on every visit.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRemoteChecksOff')]
    public function aPlainUserIsNotToldToCheckForUpdates()
    {
        $this->whenAsking();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenAsking(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'bootstrap/getEnvironment'])
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerNotificationsOff(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertFalse($json->data->check_notifications);
        self::assertFalse($json->data->loggedin);
    }

    private function outputCheckerNotificationsOn(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertTrue($json->data->check_notifications);
        self::assertTrue($json->data->loggedin);
    }

    /**
     * The default fixture user is neither an application administrator nor on a demo instance,
     * so neither remote check is offered.
     */
    private function outputCheckerRemoteChecksOff(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertFalse($json->data->check_updates);
        self::assertFalse($json->data->check_notices);
    }
}
