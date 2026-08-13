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
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Class BootstrapTest
 */
#[Group('integration')]
class BootstrapTest extends IntegrationTestCase
{

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('getEnvironmentOutputChecker')]
    public function getEnvironment()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'bootstrap/getEnvironment'])
        );

        IntegrationTestCase::runApp($container);
    }

    public function getEnvironmentOutputChecker(string $output): void
    {
        $json = json_decode($output);

        // With the body in the message: an environment call that fails answers with an error page,
        // and "null has no property lang" says nothing about why.
        self::assertNotNull($json, sprintf('The environment call did not answer with a payload: %s', $output));

        $properties = [
            'lang',
            'locale',
            'app_root',
            'max_file_size',
            'check_updates',
            'check_notices',
            'check_notifications',
            'timezone',
            'debug',
            'cookies_enabled',
            'plugins',
            'loggedin',
            'authbasic_autologin',
            'pki_key',
            'pki_max_size',
            'import_allowed_mime',
            'files_allowed_mime',
            'session_timeout',
            'csrf',
        ];

        self::assertCount(count($properties), (array)$json->data);

        foreach ($properties as $property) {
            self::assertObjectHasProperty($property, $json->data);
        }
    }

    /**
     * A route whose action segment names no real controller class is refused before any guard or
     * controller runs at all — method_exists() is checked ahead of everything else in
     * manageWebRequest(), so this is a plain 404, not a 500 from trying to instantiate a class that
     * does not exist.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aRouteNamingNoRealControllerAnswersNotFoundWithTheOopsMessage(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'index/thisControllerDoesNotExist'])
        );

        IntegrationTestCase::runApp($container);

        $response = $container->get(ResponseService::class)->getResponse();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Oops, it looks like this content does not exist...', $response->getContent());
    }

    /**
     * An exception raised while a controller action runs — after Init's guards already passed and
     * before anything has been sent — is not allowed to reach the browser as a fatal error: it is
     * turned into a normal ActionResponse::error(), rendered through the same #[Action] contract the
     * successful response would have used, with a 500 status. CategoriesController's categoriesAction
     * is PLAIN_TEXT, so the body is the bare exception message.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anExceptionDuringDispatchBecomesAPlainText500(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): never {
            throw new RuntimeException('category lookup failed');
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'items/categories'])
        );

        IntegrationTestCase::runApp($container);

        $response = $container->get(ResponseService::class)->getResponse();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('category lookup failed', $response->getContent());
    }

    /**
     * A JSON action that throws answers with the message and nothing else. The trace used to be
     * handed to the response as its data, which a JSON action serialises straight to the browser:
     * every frame's file, class and function, and — wherever the platform is configured to keep
     * them — the arguments each was called with. In a password manager those arguments are the
     * worst possible thing to put in an error page.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anExceptionInAJsonActionDoesNotCarryTheStackTrace(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): never {
            throw new RuntimeException('category lookup failed');
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'category/search'])
        );

        IntegrationTestCase::runApp($container);

        $response = $container->get(ResponseService::class)->getResponse();
        $body = $response->getContent();

        self::assertSame(500, $response->getStatusCode());

        $json = json_decode($body);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('category lookup failed', $json->description);
        self::assertEmpty($json->data, 'the response carries no trace');

        // Belt and braces: whatever shape a trace took, it would name this file and the framework
        // path it ran from.
        self::assertStringNotContainsString('/var/www/html', $body);
        self::assertStringNotContainsString('Bootstrap.php', $body);
    }

    /**
     * Maintenance mode is one of Init's guards that already sends its own redirect before throwing
     * (so the browser lands on the maintenance page rather than nothing), and the exception it
     * throws to stop the request is caught by the very same handler the test above exercises. The
     * two must not collide: manageWebRequest()'s isSent() check is what stops the second handler
     * from overwriting the redirect that was already on the wire with a 500 error page.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aMaintenanceRedirectAlreadySentIsNotOverwrittenByTheExceptionHandler(): void
    {
        $configData = self::createConfiguredStub(
            ConfigDataInterface::class,
            array_merge($this->getConfigData(), ['isMaintenance' => true])
        );
        $configFileService = $this->createStub(ConfigFileService::class);
        $configFileService->method('getConfigData')->willReturn($configData);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'items/categories']),
            [ConfigFileService::class => $configFileService]
        );

        IntegrationTestCase::runApp($container);

        $response = $container->get(ResponseService::class)->getResponse();

        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('r=error%2FmaintenanceError', (string)$response->headers->get('Location'));
    }

    /**
     * A controller that requires a completed login (ControllerBase::checkLoggedIn()) throws
     * SessionTimeout when it is not signed in — manageWebRequest() has a dedicated catch for
     * exactly this exception, separate from the general error handler above: it is not a server
     * error, so it is not turned into a 500 or an ActionResponse::error() body at all. Nothing about
     * the request is a bug; the response is whatever was already on the wire (nothing, here), left
     * alone.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aSessionTimeoutDuringDispatchIsCaughtSeparatelyFromOtherExceptions(): void
    {
        $context = self::createStub(SessionContext::class);
        $context->method('isLoggedIn')->willReturn(false);
        $context->method('getUserData')->willReturn(new UserDto());

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'accountManager/search']),
            [Context::class => $context]
        );

        IntegrationTestCase::runApp($container);

        $response = $container->get(ResponseService::class)->getResponse();

        self::assertSame('', $response->getContent());
        self::assertNotSame(500, $response->getStatusCode());
    }
}
