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
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Application\User\Ports\UserProfileService;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Adapter\In\Web\Controllers\Index\IndexController;
use SP\Infrastructure\Adapter\In\Web\Init;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Context\Session;
use SP\Infrastructure\Database\DatabaseUtil;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Log\Providers\LogHandler;
use SP\Infrastructure\ProvidersHelper;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Covers Init's session-timeout bookkeeping (initUserSession/getSessionLifeTime/
 * getSessionTimeoutForUser): the part of Init that runs once the not-installed/maintenance/
 * upgrade/database guards have already passed, deciding whether the current PHP session is still
 * good or needs restarting/regenerating.
 *
 * This is skipped by InitTest (it routes every scenario through LoginController, which is in
 * NO_SESSION_ACTIVITY precisely so it never reaches this code) and by the integration harness
 * (which stubs the context entirely). A real PHP session, in its own process, is the only way to
 * exercise it — the same technique {@see \SP\Tests\Unit\Infrastructure\Context\SessionTest} and
 * SessionLifecycleHandlerTest already use.
 */
#[Group('unitary')]
#[RunClassInSeparateProcess]
#[AllowMockObjectsWithoutExpectations]
class InitSessionTest extends UnitaryTestCase
{
    private ConfigData $configData;
    private ConfigFileService|MockObject $configMock;
    private ItemPresetService|MockObject $itemPresetService;
    private SessionKeyService|MockObject $sessionKeyService;
    private Session $session;

    /**
     * A session whose last activity is older than the configured timeout is not kept alive: it is
     * torn down and a fresh one started, which is what forces a re-login rather than silently
     * extending a session past its timeout.
     *
     * @throws Exception
     */
    public function testAnExpiredSessionIsRestarted(): void
    {
        $this->configData->setSessionTimeout(60);
        $this->session->setLastActivity(time() - 3600);

        $this->invokeInitUserSession();

        self::assertArrayNotHasKey('context', $_SESSION);
    }

    /**
     * The first request of a session (no prior activity recorded) just records "now" as the last
     * activity — there is nothing to expire yet and no ID old enough to need regenerating.
     *
     * @throws Exception
     */
    public function testAFreshSessionsFirstActivityIsRecorded(): void
    {
        $this->configData->setSessionTimeout(3600);

        $this->invokeInitUserSession();

        self::assertArrayHasKey('context', $_SESSION);
        self::assertGreaterThan(0, $this->session->getLastActivity());
    }

    /**
     * A session ID that has lived past the regeneration window is re-keyed for a logged-in user
     * (encrypting the vault under a fresh session key) rather than left as-is indefinitely — this
     * is what limits how long a single session key stays in use.
     *
     * @throws Exception
     */
    public function testAnOldSessionIdIsRekeyedForALoggedInUser(): void
    {
        $this->configData->setSessionTimeout(3600);
        $this->session->setLastActivity(time());
        $this->session->setSidStartTime(time() - 3600);
        $this->session->setUserData(new UserDto(login: 'admin'));

        $this->sessionKeyService->expects(self::once())->method('reKey')->with($this->session);

        $this->invokeInitUserSession();

        self::assertArrayHasKey('context', $_SESSION);
    }

    /**
     * If re-keying itself fails, the session is not left in a half-migrated state: it is restarted
     * outright (forcing a fresh login) instead of carrying on as though re-keying had succeeded.
     *
     * @throws Exception
     */
    public function testARekeyFailureRestartsTheSessionInstead(): void
    {
        $this->configData->setSessionTimeout(3600);
        $this->session->setLastActivity(time());
        $this->session->setSidStartTime(time() - 3600);
        $this->session->setUserData(new UserDto(login: 'admin'));

        $this->sessionKeyService->method('reKey')->willThrowException(new CryptException('re-key failed'));

        $this->invokeInitUserSession();

        self::assertArrayNotHasKey('context', $_SESSION);
    }

    /**
     * The very first call on a brand new session (before Session::initialize() has even stamped a
     * SID start time) has nothing to compare a regeneration window against yet, so there is no ID
     * old enough to regenerate or re-key — it falls straight through to recording the activity.
     *
     * The line this exercises also calls ini_set('session.gc_maxlifetime', ...) to apply the
     * configured timeout to PHP's own session lifetime — this is a no-op in practice, and not just
     * in this test: by the time initUserSession() runs in real use, Init::initialize() has already
     * called Context::initialize(), which starts the PHP session, and ini_set() cannot change
     * session.gc_maxlifetime once a session is active. PHP raises "Session ini settings cannot be
     * changed when a session is active" and leaves the ini value untouched every time this branch
     * runs — confirmed by letting the warning through once while developing this test. It is
     * swallowed below (matched on its exact message, so anything else still surfaces) purely so
     * this test does not itself report a warning; the underlying no-op is a pre-existing production
     * behaviour this test does not change or assert as correct.
     *
     * @throws Exception
     */
    public function testASessionWithNoRecordedSidStartTimeFallsThroughToRecordingActivity(): void
    {
        $this->configData->setSessionTimeout(120);
        $this->session->setSidStartTime(0);

        set_error_handler(static function (int $errno, string $errstr): bool {
            return str_contains($errstr, 'Session ini settings cannot be changed when a session is active');
        });

        try {
            $this->invokeInitUserSession();
        } finally {
            restore_error_handler();
        }

        self::assertGreaterThan(0, $this->session->getLastActivity());
    }

    /**
     * initialize() only reaches initUserSession() for a controller outside NO_SESSION_ACTIVITY,
     * once every earlier guard (installed/maintenance/upgrade/database/CSRF) has already passed —
     * this is the full path InitTest itself never exercises, since every one of its scenarios
     * deliberately routes through LoginController to stay out of session bookkeeping.
     *
     * @throws Exception
     */
    public function testInitializeReachesSessionBookkeepingForAnOrdinaryController(): void
    {
        $currentVersion = Version::getVersionStringNormalized();
        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);
        $this->configData->setSessionTimeout(3600);

        // A fresh, NOT-yet-initialized session: initialize() itself calls Context::initialize()
        // as its first step (Session::initialize() refuses to bind twice), unlike every other test
        // in this class, which bypasses that call by invoking the private methods directly against
        // the already-initialized $this->session built in buildContext().
        $freshSession = new Session();
        $freshApplication = new Application(
            $this->configMock,
            $this->createStub(EventDispatcherInterface::class),
            $freshSession
        );

        $response = $this->createStub(ResponseService::class);
        $symfonyRequest = new SymfonyRequest();
        $symfonyRequest->query->set('r', 'index/index');
        $router = new Router($symfonyRequest, $response);

        $request = $this->createStub(RequestService::class);
        $request->method('checkReload')->willReturn(false);
        $request->method('getClientAddress')->willReturn('127.0.0.1');

        $databaseUtil = $this->createStub(DatabaseUtil::class);
        $databaseUtil->method('checkDatabaseConnection')->willReturn(true);
        $databaseUtil->method('checkDatabaseTables')->willReturn(true);

        $csrf = $this->createStub(CsrfHandler::class);
        $csrf->method('check')->willReturn(true);

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://example.test');

        $logHandler = new LogHandler(
            $freshApplication,
            $this->createStub(LoggerInterface::class),
            $this->createStub(LanguageInterface::class),
            $this->createStub(RequestService::class)
        );

        $init = new Init(
            $freshApplication,
            new ProvidersHelper($logHandler),
            $request,
            $router,
            $this->createStub(AppLockHandler::class),
            $csrf,
            $this->createStub(LanguageInterface::class),
            $this->itemPresetService,
            $databaseUtil,
            $this->createStub(UserProfileService::class),
            $uriContext,
            $this->sessionKeyService
        );

        $init->initialize(IndexController::class);

        self::assertGreaterThan(0, $freshSession->getLastActivity());
    }

    /**
     * The control constant every module implements — trivial, but it is what tells Bootstrap which
     * module built the container it is running.
     */
    public function testGetNameIdentifiesTheWebModule(): void
    {
        self::assertSame('web', $this->buildInit()->getName());
    }

    /**
     * getSessionLifeTime() is only asked to look up a per-user override on the index page (or when
     * nothing is cached yet); everywhere else the session's own stored timeout is trusted as-is.
     * Signed out, there is no per-user override to find, so the configured default is what is
     * stored and returned.
     *
     * @throws Exception
     */
    public function testSessionLifeTimeFallsBackToTheConfiguredDefaultWhenSignedOut(): void
    {
        $this->configData->setSessionTimeout(1800);

        $init = $this->buildInit();
        $this->markAsIndexPage($init);

        $lifetime = $this->invokePrivate($init, 'getSessionLifeTime');

        self::assertSame(1800, $lifetime);
        self::assertSame(1800, $this->session->getSessionTimeout());
    }

    /**
     * A broken per-user preset lookup does not take the whole request down with it: the exception
     * is logged and the configured default is used instead, the same outcome as if there had been
     * no override to find at all.
     *
     * @throws Exception
     */
    public function testSessionLifeTimeFallsBackWhenThePresetLookupFails(): void
    {
        $this->configData->setSessionTimeout(900);
        $this->session->setUserData(new UserDto(login: 'admin'));

        $this->itemPresetService->method('getForCurrentUser')->willThrowException(new RuntimeException('lookup failed'));

        $init = $this->buildInit();
        $this->markAsIndexPage($init);

        $lifetime = $this->invokePrivate($init, 'getSessionLifeTime');

        self::assertSame(900, $lifetime);
    }

    /**
     * @throws Exception
     */
    private function invokeInitUserSession(): void
    {
        $this->invokePrivate($this->buildInit(), 'initUserSession');
    }

    /**
     * @throws Exception
     */
    private function invokePrivate(Init $init, string $method): mixed
    {
        return (new ReflectionMethod($init, $method))->invoke($init);
    }

    /**
     * getSessionLifeTime() only looks up a per-user override when isIndex is true; that flag is
     * set from the constructor's $controller argument, which initUserSession() tests bypass, so it
     * is poked directly for the tests that need it.
     */
    private function markAsIndexPage(Init $init): void
    {
        (new ReflectionProperty($init, 'isIndex'))->setValue($init, true);
    }

    /**
     * @throws Exception
     */
    private function buildInit(): Init
    {
        $response = $this->createStub(ResponseService::class);
        $router = new Router(new SymfonyRequest(), $response);

        $request = $this->createStub(RequestService::class);
        $request->method('checkReload')->willReturn(false);
        $request->method('getClientAddress')->willReturn('127.0.0.1');

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://example.test');

        $logHandler = new LogHandler(
            $this->application,
            $this->createStub(LoggerInterface::class),
            $this->createStub(LanguageInterface::class),
            $this->createStub(RequestService::class)
        );

        return new Init(
            $this->application,
            new ProvidersHelper($logHandler),
            $request,
            $router,
            $this->createStub(AppLockHandler::class),
            $this->createStub(CsrfHandler::class),
            $this->createStub(LanguageInterface::class),
            $this->itemPresetService,
            $this->createStub(DatabaseUtil::class),
            $this->createStub(UserProfileService::class),
            $uriContext,
            $this->sessionKeyService
        );
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->itemPresetService = $this->createMock(ItemPresetService::class);
        $this->sessionKeyService = $this->createMock(SessionKeyService::class);
    }

    /**
     * Called by UnitaryTestCase::setUp() (via buildApplication()) before this class's own setUp()
     * body runs — Init needs the same real, session-backed Context the rest of the fixture (and
     * $this->session, used directly by the tests) is built against.
     *
     * @throws Exception
     */
    protected function buildContext(): Session
    {
        $this->session = new Session();
        $this->session->initialize();

        return $this->session;
    }

    /**
     * @throws Exception
     */
    protected function buildConfig(): ConfigFileService
    {
        $this->configData = new ConfigData([ConfigDataInterface::PASSWORD_SALT => self::$faker->sha1()]);

        $this->configMock = $this->createStub(ConfigFileService::class);
        $this->configMock->method('getConfigData')->willReturn($this->configData);

        return $this->configMock;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
