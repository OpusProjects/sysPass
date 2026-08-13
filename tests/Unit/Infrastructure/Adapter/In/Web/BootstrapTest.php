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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use SP\Domain\Common\Attributes\Action;
use SP\Domain\Common\Dtos\ActionResponse;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Common\Enums\ResponseType;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Exceptions\InitializationException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Bootstrap;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Events\EventDispatcher;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Class BootstrapTest
 *
 * Covers the two checks the web dispatcher enforces on every controller action before it is
 * allowed to run (Bootstrap::getMethod()) and how each of the response contract's declared
 * shapes is actually rendered (Bootstrap::buildResponse()).
 *
 * getMethod() is the check that catches a controller whose action does not honour the
 * ActionResponse/#[Action] contract at dispatch time rather than as a fatal error mid-render —
 * this is the check CLAUDE.md credits with catching "a whole class of production breakage"
 * before it shipped, so both ways a method can violate the contract are pinned here individually.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class BootstrapTest extends UnitaryTestCase
{
    private ResponseService|MockObject $response;

    /**
     * A method whose return type is not ActionResponse at all must be rejected before it is ever
     * invoked — this is what stops a controller that was never migrated to the ActionResponse
     * contract from being dispatched to.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testGetMethodRejectsAnIncorrectReturnType(): void
    {
        $bootstrap = $this->buildBootstrap('wrongReturnTypeAction');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage("Incorrect method return type: expected 'SP\Domain\Common\Dtos\ActionResponse'");

        $bootstrap->getMethod(BootstrapTestFixtureController::class);
    }

    /**
     * A method that does return ActionResponse but was never decorated with #[Action] is rejected
     * too — the return type alone does not say how the response should be rendered, so
     * buildResponse() would have nothing to switch on.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testGetMethodRejectsAMethodNotMarkedAsAnAction(): void
    {
        $bootstrap = $this->buildBootstrap('missingAttributeAction');

        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Method is not defined as an action');

        $bootstrap->getMethod(BootstrapTestFixtureController::class);
    }

    /**
     * The control: a method that satisfies both halves of the contract is accepted, so the two
     * refusals above are the checks firing and not getMethod() rejecting everything.
     *
     * @throws Exception
     * @throws InitializationException
     */
    public function testGetMethodAcceptsAProperlyDeclaredAction(): void
    {
        $bootstrap = $this->buildBootstrap('properAction');

        $method = $bootstrap->getMethod(BootstrapTestFixtureController::class);

        self::assertSame('properAction', $method->getName());
    }

    /**
     * A PLAIN_TEXT action's response body is the ActionResponse subject verbatim — this is the
     * rendering path almost every page controller uses.
     *
     * @throws Exception
     */
    public function testBuildResponseRendersPlainTextBody(): void
    {
        $bootstrap = $this->buildBootstrap('properAction');
        $method = new ReflectionMethod(BootstrapTestFixtureController::class, 'properAction');

        $this->response->expects(self::once())->method('body')->with('Hello there')->willReturnSelf();

        $this->invokeBuildResponse($bootstrap, $method, ActionResponse::ok('Hello there'));
    }

    /**
     * A CALLBACK action does not go through body()/toPlain()/toJson() at all: the subject is a
     * closure invoked directly against the response, bound to the Bootstrap instance so it can
     * reach the same helpers a normal render would (e.g. setCors()).
     *
     * @throws Exception
     */
    public function testBuildResponseInvokesTheCallbackSubject(): void
    {
        $bootstrap = $this->buildBootstrap('callbackAction');
        $method = new ReflectionMethod(BootstrapTestFixtureController::class, 'callbackAction');

        $received = null;
        $subject = function (ResponseService $response) use (&$received): void {
            $received = $response;
        };

        $this->response->expects(self::never())->method('body');

        $this->invokeBuildResponse($bootstrap, $method, new ActionResponse(
            ResponseStatus::OK,
            $subject
        ));

        self::assertSame($this->response, $received);
    }

    /**
     * Bootstrap::run()'s own top-level catch is a last resort: everything manageWebRequest() can
     * throw is already caught inside the request closure, and Router::dispatch()'s onError handler
     * catches whatever a closure lets past. What is left for this catch is a container failure
     * during initializeModule()/handleRequest() itself, before any request-level handling exists to
     * catch it — a broken DI wiring, not a broken request. It answers with die($e->getMessage()),
     * printed with no wrapper and no template, so the exact text is what a broken deployment shows
     * a visitor; a real subprocess is required because die() would otherwise kill the test runner.
     */
    public function testRunDiesWithTheContainerExceptionMessageWhenModuleInitializationFails(): void
    {
        $output = $this->runBootstrapRunInASubprocess(
            'SP\Infrastructure\Adapter\In\Web\Bootstrap',
            'container wiring is broken'
        );

        self::assertSame('container wiring is broken', $output);
    }

    /**
     * Runs Bootstrap::run($bootstrapClass) against a BootstrapInterface double whose
     * initializeModule() throws a ContainerExceptionInterface, in a real subprocess so die()
     * terminates that process rather than the test runner. Returns exactly what was printed to
     * stdout.
     */
    private function runBootstrapRunInASubprocess(string $bootstrapClass, string $exceptionMessage): string
    {
        $script = <<<PHP
            define('APP_ROOT', getenv('SP_APP_ROOT'));
            require getenv('SP_AUTOLOAD');
            define('APP_PATH', APP_ROOT);
            require APP_ROOT . '/src/Infrastructure/Functions.php';

            final class RunTestContainerException extends \\RuntimeException implements \\Psr\\Container\\ContainerExceptionInterface {}

            final class RunTestThrowingBootstrap implements \\SP\\Domain\\Core\\Bootstrap\\BootstrapInterface {
                public function initializeModule(\\SP\\Domain\\Core\\Bootstrap\\ModuleInterface \$module): void {
                    throw new RunTestContainerException('{$exceptionMessage}');
                }
                public function handleRequest(): void {}
            }

            final class RunTestFakeModule implements \\SP\\Domain\\Core\\Bootstrap\\ModuleInterface {
                public function initialize(string \$controller): void {}
                public function getName(): string { return 'web'; }
            }

            {$bootstrapClass}::run(new RunTestThrowingBootstrap(), new RunTestFakeModule());
            PHP;

        $command = sprintf(
            'SP_AUTOLOAD=%s SP_APP_ROOT=%s DEBUG=false %s -r %s 2>&1',
            escapeshellarg(REAL_APP_ROOT . '/vendor/autoload.php'),
            escapeshellarg(REAL_APP_ROOT),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        );

        return (string)shell_exec($command);
    }

    /**
     * @throws Exception
     */
    private function invokeBuildResponse(Bootstrap $bootstrap, ReflectionMethod $method, ActionResponse $actionResponse): void
    {
        $reflection = new ReflectionMethod($bootstrap, 'buildResponse');
        $reflection->invoke($bootstrap, $method, $actionResponse, $this->response);
    }

    /**
     * Builds a real Bootstrap instance (not through the DI container) with the route's methodName
     * pointed at a fixture controller method, so getMethod()/buildResponse() can be exercised
     * directly without a full request dispatch.
     *
     * @throws Exception
     */
    private function buildBootstrap(string $methodName): Bootstrap
    {
        $symfonyRequest = new SymfonyRequest();
        $this->response = $this->createMock(ResponseService::class);
        $router = new Router($symfonyRequest, $this->response);

        $request = $this->createStub(RequestService::class);
        $request->method('getHttpHost')->willReturn('example.test');

        $configData = new ConfigData([ConfigDataInterface::PASSWORD_SALT => self::$faker->sha1()]);

        $routeContextData = new RouteContextData('fixture', 'fixture', $methodName, []);

        return new Bootstrap(
            $configData,
            $router,
            $request,
            new PhpExtensionChecker(),
            $this->context,
            $this->createStub(ContainerInterface::class),
            $this->response,
            $routeContextData,
            $this->createStub(LanguageInterface::class),
            new PathsContext(),
            new EventDispatcher()
        );
    }
}

/**
 * Fixture controller used only to exercise Bootstrap::getMethod()/buildResponse() against methods
 * that deliberately violate, or honour, the ActionResponse/#[Action] contract.
 */
final class BootstrapTestFixtureController
{
    /** Wrong return type: not ActionResponse at all. */
    public function wrongReturnTypeAction(): bool
    {
        return true;
    }

    /** Right return type, but missing the #[Action] attribute. */
    public function missingAttributeAction(): ActionResponse
    {
        return ActionResponse::ok('');
    }

    #[Action(ResponseType::PLAIN_TEXT)]
    public function properAction(): ActionResponse
    {
        return ActionResponse::ok('');
    }

    #[Action(ResponseType::CALLBACK)]
    public function callbackAction(): ActionResponse
    {
        return ActionResponse::ok('');
    }
}
