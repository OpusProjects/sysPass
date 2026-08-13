<?php

declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api;

use Closure;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\ModuleInterface;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Api\Bootstrap;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Context\Stateless;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Unit tests for the REST Bootstrap: mapExceptionToHttpCode()'s SQLSTATE-vs-HTTP-code mapping,
 * run()'s top-level container-failure catch, and handleRestRequest()'s own defence against a
 * ROUTE_MAP entry whose target controller method does not exist.
 */
#[Group('unitary')]
class BootstrapTest extends TestCase
{
    private static function callMapExceptionToHttpCode(\Throwable $e): int
    {
        $method = (new ReflectionClass(Bootstrap::class))
            ->getMethod('mapExceptionToHttpCode');

        return $method->invoke(null, $e);
    }

    public static function provideIntCodeExceptions(): array
    {
        return [
            'int code 404 → 404'                => [new RuntimeException('Not found',   404), 404],
            'int code 500 → 500'                 => [new RuntimeException('Server err',  500), 500],
            'int code 999 → 500 (out of range)'  => [new RuntimeException('Custom code', 999), 500],
            'int code 0 → 500 (zero)'            => [new RuntimeException('No code',       0), 500],
            'int code 399 → 500 (below range)'   => [new RuntimeException('Below range', 399), 500],
            'int code 600 → 500 (above range)'   => [new RuntimeException('Above range', 600), 500],
        ];
    }

    #[DataProvider('provideIntCodeExceptions')]
    public function testMapExceptionToHttpCodeWithIntCodes(\Throwable $exception, int $expected): void
    {
        $this->assertSame($expected, self::callMapExceptionToHttpCode($exception));
    }

    /**
     * Set a SQLSTATE string code on a PDOException via Reflection (the $code
     * property is protected in PHP 8.5 so direct assignment is forbidden).
     */
    private static function makePdoException(string $sqlstate): PDOException
    {
        $e = new PDOException('SQLSTATE[' . $sqlstate . ']');
        $prop = new \ReflectionProperty($e, 'code');
        $prop->setValue($e, $sqlstate);

        return $e;
    }

    /**
     * PDOException::getCode() returns a SQLSTATE string (e.g. "42S02").
     * Verifies that a string SQLSTATE code does not cause a TypeError and
     * is mapped to 500 (not a valid HTTP code).
     */
    public function testMapExceptionToHttpCodeWithPdoStringSqlState(): void
    {
        $this->assertSame(500, self::callMapExceptionToHttpCode(self::makePdoException('42S02')));
    }

    public function testMapExceptionToHttpCodeWithPdoDeadlockState(): void
    {
        // '40001' looks numeric but is not a valid HTTP status range → 500.
        $this->assertSame(500, self::callMapExceptionToHttpCode(self::makePdoException('40001')));
    }

    /**
     * Bootstrap::run()'s own top-level catch is a last resort for a container failure during
     * initializeModule()/handleRequest() itself — everything a REST call can throw is already
     * caught inside handleRestRequest()'s own try/catch and mapped to a JSON error body. It answers
     * with die($e->getMessage()), printed with no wrapper, so the exact text is what a broken
     * deployment shows the caller; a real subprocess is required because die() would otherwise
     * kill the test runner.
     */
    public function testRunDiesWithTheContainerExceptionMessageWhenModuleInitializationFails(): void
    {
        $script = <<<'PHP'
            define('APP_ROOT', getenv('SP_APP_ROOT'));
            require getenv('SP_AUTOLOAD');
            define('APP_PATH', APP_ROOT);
            require APP_ROOT . '/src/Infrastructure/Functions.php';

            final class ApiRunTestContainerException extends \RuntimeException implements \Psr\Container\ContainerExceptionInterface {}

            final class ApiRunTestThrowingBootstrap implements \SP\Domain\Core\Bootstrap\BootstrapInterface {
                public function initializeModule(\SP\Domain\Core\Bootstrap\ModuleInterface $module): void {
                    throw new ApiRunTestContainerException('api container wiring is broken');
                }
                public function handleRequest(): void {}
            }

            final class ApiRunTestFakeModule implements \SP\Domain\Core\Bootstrap\ModuleInterface {
                public function initialize(string $controller): void {}
                public function getName(): string { return 'api'; }
            }

            \SP\Infrastructure\Adapter\In\Api\Bootstrap::run(new ApiRunTestThrowingBootstrap(), new ApiRunTestFakeModule());
            PHP;

        $command = sprintf(
            'SP_AUTOLOAD=%s SP_APP_ROOT=%s DEBUG=false %s -r %s 2>&1',
            escapeshellarg(REAL_APP_ROOT . '/vendor/autoload.php'),
            escapeshellarg(REAL_APP_ROOT),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        );

        $output = (string)shell_exec($command);

        self::assertSame('api container wiring is broken', $output);
    }

    /**
     * handleRestRequest() re-checks method_exists() on the controller class its own ROUTE_MAP
     * points at, the same defence Web\Bootstrap applies to a user-supplied route — except every
     * ROUTE_MAP entry is fixed data the codebase controls, so this branch only fires if that table
     * itself is wrong (a route added without its controller method, or a typo in the action name),
     * not from anything a caller can trigger. Exercised directly against handleRestRequest() with a
     * controller name that resolves to no class at all, standing in for that data bug.
     *
     * @throws Exception
     */
    public function testHandleRestRequestAnswersNotFoundWhenTheRouteMapPointsAtAMissingMethod(): void
    {
        $response = $this->createMock(ResponseService::class);
        $response->method('headers')->willReturn(new ResponseHeaderBag());
        $response->expects(self::once())->method('code')->with(404)->willReturnSelf();
        $response->expects(self::once())
                 ->method('body')
                 ->with(self::callback(static function (string $body): bool {
                     $decoded = json_decode($body, true);

                     return ($decoded['error']['message'] ?? null) === 'Endpoint not found';
                 }))
                 ->willReturnSelf();

        $bootstrap = $this->buildBootstrap();
        $bootstrap->initializeModule($this->createConfiguredStub(ModuleInterface::class, ['getName' => 'api']));

        $closure = $this->getHandleRestRequestClosure($bootstrap, 'doesNotExist', 'view');

        $closure(new SymfonyRequest(), $response);
    }

    /**
     * @throws Exception
     */
    private function getHandleRestRequestClosure(Bootstrap $bootstrap, string $controllerName, string $actionName): Closure
    {
        $reflection = new ReflectionMethod($bootstrap, 'handleRestRequest');

        return $reflection->invoke($bootstrap, $controllerName, $actionName);
    }

    /**
     * Builds a real Api Bootstrap instance (not through the DI container), matching the pattern
     * used for the Web Bootstrap unit tests.
     *
     * @throws Exception
     */
    private function buildBootstrap(): Bootstrap
    {
        $symfonyRequest = new SymfonyRequest();
        $response = $this->createStub(ResponseService::class);
        $router = new Router($symfonyRequest, $response);

        $request = $this->createStub(RequestService::class);
        $request->method('getHttpHost')->willReturn('example.test');

        $configData = new ConfigData([ConfigDataInterface::PASSWORD_SALT => 'salt']);

        $routeContextData = new RouteContextData('account', 'view', 'viewAction', []);

        return new Bootstrap(
            $configData,
            $router,
            $request,
            new PhpExtensionChecker(),
            new Stateless(),
            $this->createStub(ContainerInterface::class),
            $response,
            $routeContextData,
            $this->createStub(LanguageInterface::class),
            new PathsContext(),
            new EventDispatcher()
        );
    }
}
