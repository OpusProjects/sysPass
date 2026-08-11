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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Login;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Auth\Ports\LoginService;
use SP\Domain\Auth\Dtos\LoginResponseDto;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the login endpoints. Only the browser suite exercised them, so nothing here was
 * covered by PHPUnit.
 *
 * The authentication itself belongs to the login service; these assert the controller's own
 * contract — where a successful sign-in sends the user, and that a failure is reported as an
 * error instead of leaking through.
 */
#[Group('integration')]
class LoginTest extends IntegrationTestCase
{
    private const REDIRECT = 'index.php?r=index';

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRedirect')]
    public function loginSucceeds()
    {
        $loginService = $this->createStub(LoginService::class);
        $loginService->method('doLogin')->willReturn(new LoginResponseDto(LoginStatus::OK, self::REDIRECT));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'login/login'],
                ['user' => self::$faker->userName(), 'pass' => self::$faker->password()]
            ),
            [LoginService::class => $loginService]
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A rejected sign-in has to come back as an error response. Letting the exception escape
     * would render a page carrying its trace.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRejected')]
    public function loginFailureIsReportedAsAnError()
    {
        $loginService = $this->createStub(LoginService::class);
        $loginService->method('doLogin')->willThrowException(new AuthException('Wrong login'));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'login/login'],
                ['user' => self::$faker->userName(), 'pass' => self::$faker->password()]
            ),
            [LoginService::class => $loginService]
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerLoginPage')]
    public function index()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'login/index'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotEmptyPage')]
    public function logout()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'login/logout'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The browser is told where to go next; without it a successful sign-in leaves the user on
     * the login form.
     */
    private function outputCheckerRedirect(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertSame(self::REDIRECT, $json->data->url);
    }

    private function outputCheckerRejected(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Wrong login', $json->description);
    }

    /**
     * The sign-in form has to offer both credential fields.
     */
    private function outputCheckerLoginPage(string $output): void
    {
        self::assertStringContainsString('name="user"', $output);
        self::assertStringContainsString('name="pass"', $output);
    }

    private function outputCheckerNotEmptyPage(string $output): void
    {
        self::assertNotEmpty($output);
        self::assertStringContainsString('<html', $output);
    }
}
