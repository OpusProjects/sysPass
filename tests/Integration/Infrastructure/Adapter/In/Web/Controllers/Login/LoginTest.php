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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Events\EventReceiver;
use SP\Domain\Core\Messages\TextFormatter;
use SP\Infrastructure\Events\EventDispatcher;
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

    /** Where the user was when their session sent them to the login page, if anywhere. */
    private ?string $interruptedAt = null;
    private bool $demoEnabled = false;
    /** @var Event[] */
    private array $recordedEvents = [];

    protected function getContext(): SessionContext|Stub
    {
        $context = parent::getContext();
        $context->method('getTrasientKey')->willReturnCallback(
            fn(string $key) => $key === 'redirect' ? $this->interruptedAt : null
        );

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConfigData(): array
    {
        return array_merge(parent::getConfigData(), ['isDemoEnabled' => $this->demoEnabled]);
    }

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
    /**
     * Where a user is sent after signing in is the session's business first: if they were bounced
     * to the login page from somewhere, that is where they go back to, and only otherwise to
     * wherever the login service would have sent them. The wrong way round drops a user on the
     * home page every time their session expires mid-task.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerReturnedToWhereTheyWere')]
    public function aUserBouncedToTheLoginPageGoesBackToWhereTheyWere()
    {
        $this->interruptedAt = 'index.php?r=account/view/100';

        $this->whenSigningIn();
    }

    /**
     * A sign-in that arrived through a proxy records where it was forwarded from, so an operator
     * reading the log sees the client rather than the proxy. On a demo instance the address is
     * masked instead — that log is readable by whoever is trying the demo out.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('forwardedProvider')]
    public function aForwardedSignInRecordsWhereItCameFrom(bool $demo, string $expected)
    {
        $this->demoEnabled = $demo;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.43, 198.51.100.7';

        try {
            $this->whenSigningIn();
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        $forwarded = $this->recordedEventsNamed('login.info');

        self::assertCount(1, $forwarded, 'a forwarded sign-in is recorded');
        self::assertStringContainsString(
            $expected,
            $forwarded[0]->getEventMessage()->getDetails(new TextFormatter(), false)
        );
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function forwardedProvider(): array
    {
        return [
            'an ordinary instance logs the client address' => [false, '192.0.2.43'],
            'a demo instance masks it' => [true, '***'],
        ];
    }

    /**
     * A sign-in that did not arrive through a proxy records nothing extra — there is nothing to
     * say, and an empty detail in the log is noise.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerRedirect')]
    public function anOrdinarySignInRecordsNoForwardedAddress()
    {
        $this->whenSigningIn();

        self::assertEmpty($this->recordedEventsNamed('login.info'));
    }

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
    /**
     * Signing in with a stubbed login service that always succeeds, against a real event
     * dispatcher whose events this test records — the dispatcher is final and typed concretely on
     * the controller, so it cannot be doubled.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenSigningIn(): void
    {
        $loginService = $this->createStub(LoginService::class);
        $loginService->method('doLogin')->willReturn(new LoginResponseDto(LoginStatus::OK, self::REDIRECT));

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->attach(
            new class ($this->recordedEvents) implements EventReceiver {
                /**
                 * @param Event[] $recorded
                 */
                public function __construct(private array &$recorded)
                {
                }

                public function update(Event $event): void
                {
                    $this->recorded[] = $event;
                }

                public function getEvents(): ?string
                {
                    return '*';
                }
            }
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'login/login'],
                ['user' => self::$faker->userName(), 'pass' => self::$faker->password()]
            ),
            [LoginService::class => $loginService, EventDispatcherInterface::class => $eventDispatcher]
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @return Event[]
     */
    private function recordedEventsNamed(string $name): array
    {
        return array_values(
            array_filter($this->recordedEvents, static fn(Event $event) => $event->getName() === $name)
        );
    }

    private function outputCheckerReturnedToWhereTheyWere(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertSame($this->interruptedAt, $json->data->url);
    }

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
