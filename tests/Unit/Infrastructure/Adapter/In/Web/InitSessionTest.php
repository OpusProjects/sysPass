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
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\User\Models\User;
use SP\Domain\Common\Providers\Version;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CsrfHandler;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\ItemPreset\Models\ItemPreset;
use SP\Domain\ItemPreset\Models\SessionTimeout;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
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
    private MockObject|UserService $userService;
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

        $freshSession = new Session();

        $init = $this->buildInitForAFreshSession($freshSession);

        $init->initialize(IndexController::class);

        self::assertGreaterThan(0, $freshSession->getLastActivity());
    }

    /**
     * PHP's own session collector is free to delete a session file older than
     * session.gc_maxlifetime, whatever the application thinks. Telling it the configured timeout
     * therefore has to happen before the session is started: PHP refuses the change once one is
     * active, and the attempt that used to be made afterwards silently did nothing at all — so an
     * installation configured for a long session could still have its files collected at whatever
     * the platform default happened to be.
     *
     * @throws Exception
     */
    public function testTheConfiguredSessionTimeoutReachesPhpsOwnCollector(): void
    {
        $currentVersion = Version::getVersionStringNormalized();
        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);
        $this->configData->setSessionTimeout(4321);

        // Built first: it closes the session the harness started, which is also what lets the
        // baseline below be set at all.
        $init = $this->buildInitForAFreshSession(new Session());

        ini_set('session.gc_maxlifetime', '1440');

        $init->initialize(IndexController::class);

        self::assertSame('4321', ini_get('session.gc_maxlifetime'));
    }


    /**
     * A session must not outlive the account it belongs to.
     *
     * The API re-reads the user on every request and refuses a disabled one
     * (`Api::setUserData()`), but the web only ever checked at login — so disabling an account
     * stopped its API token at once and left its browser session working. Nothing else caught up
     * with it either: the timeout is measured from the last request, so the session being actively
     * used is exactly the one that never expires, and an account is usually disabled because of
     * what is being done with it right now.
     *
     * Signed in first and the session then written out, so `initialize()` picks it up the way a
     * returning request does rather than being handed one that was never stored.
     *
     * @throws Exception
     */
    public function testASessionWhoseAccountHasBeenDisabledIsEnded(): void
    {
        $this->givenAnInstalledInstance();
        $this->session->setUserData(new UserDto(id: 7, login: 'admin'));

        $this->userService = $this->createStub(UserService::class);
        $this->userService->method('getById')->willReturn(new User(['id' => 7, 'isDisabled' => true]));

        $init = $this->buildInitForAFreshSession(new Session());

        $init->initialize(IndexController::class);

        self::assertArrayNotHasKey('context', $_SESSION, 'the session must be gone');
    }

    /**
     * The control. Without it the assertion above is satisfied by ending every session, which is
     * not a check on the account at all.
     *
     * @throws Exception
     */
    public function testASessionWhoseAccountIsStillEnabledSurvives(): void
    {
        $this->givenAnInstalledInstance();
        $this->session->setUserData(new UserDto(id: 7, login: 'admin'));

        $this->userService = $this->createStub(UserService::class);
        $this->userService->method('getById')->willReturn(new User(['id' => 7, 'isDisabled' => false]));

        $freshSession = $this->buildInitForAFreshSession($session = new Session());

        $freshSession->initialize(IndexController::class);

        self::assertArrayHasKey('context', $_SESSION, 'an enabled account keeps its session');
        self::assertSame('admin', $session->getUserData()->login);
    }

    /**
     * A read that fails leaves the session alone, deliberately.
     *
     * This guard runs on every request of every session, so its failure mode is the whole design:
     * treating "could not read the account" as "the account is disabled" would sign every user out
     * the moment the database hiccuped, turning a blip into an outage. Only a positive answer ends
     * a session; an account that has been deleted rather than disabled is left to the ordinary
     * expiry for the same reason.
     *
     * @throws Exception
     */
    public function testASessionSurvivesAnAccountReadThatFails(): void
    {
        $this->givenAnInstalledInstance();
        $this->session->setUserData(new UserDto(id: 7, login: 'admin'));

        $this->userService = $this->createStub(UserService::class);
        $this->userService->method('getById')
                          ->willThrowException(NoSuchItemException::error('User does not exist'));

        $init = $this->buildInitForAFreshSession(new Session());

        $init->initialize(IndexController::class);

        self::assertArrayHasKey('context', $_SESSION, 'a failed read must not end the session');
    }

    private function givenAnInstalledInstance(): void
    {
        $currentVersion = Version::getVersionStringNormalized();

        $this->configData->setInstalled(true);
        $this->configData->setMaintenance(false);
        $this->configData->setAppVersion($currentVersion);
        $this->configData->setDatabaseVersion($currentVersion);
        $this->configData->setSessionTimeout(3600);
    }

    /**
     * Everything Init needs to reach session bookkeeping for an ordinary controller, against a
     * session that has not been initialized yet — initialize() calls Context::initialize() itself
     * as its first step, and Session::initialize() refuses to bind twice.
     *
     * @throws Exception
     */
    private function buildInitForAFreshSession(Session $freshSession): Init
    {
        // The harness builds its context — and so starts a session — in setUp, whereas a real
        // request reaches initialize() with none active. Close it, so these tests ask what
        // production asks, including of anything that has to happen before a session exists.
        session_write_close();

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
            $this->userService,
            $this->sessionKeyService
        );


        return $init;
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
     * The point of a session-timeout preset: an administrator says sessions from a given address
     * last a given time, and that is the time the session gets — not the instance-wide default.
     * Every other test here reaches this code by the preset *not* applying, which cannot tell a
     * working rule from one that never matches anything.
     *
     * @throws Exception
     */
    public function testASessionTimeoutPresetForTheClientsAddressIsWhatApplies(): void
    {
        $this->configData->setSessionTimeout(1800);
        $this->session->setUserData(new UserDto(login: 'admin'));
        $this->givenASessionTimeoutPresetFor('127.0.0.1', 120);

        $init = $this->buildInit();
        $this->markAsIndexPage($init);

        self::assertSame(120, $this->invokePrivate($init, 'getSessionLifeTime'));
        self::assertSame(120, $this->session->getSessionTimeout());
    }

    /**
     * And it applies by subnet, not by an exact match on the text: a preset written for a range
     * covers every address in it. Without this the rule above would be satisfied by a comparison
     * that ignored the mask entirely.
     *
     * @throws Exception
     */
    public function testAPresetWrittenForARangeCoversAnAddressInIt(): void
    {
        $this->configData->setSessionTimeout(1800);
        $this->session->setUserData(new UserDto(login: 'admin'));
        $this->givenASessionTimeoutPresetFor('127.0.0.0/8', 300);

        $init = $this->buildInit();
        $this->markAsIndexPage($init);

        self::assertSame(300, $this->invokePrivate($init, 'getSessionLifeTime'));
    }

    /**
     * A preset for somebody else's address is not this client's rule, and the configured default
     * stands. This is the half that makes the preset a rule about an address rather than a global
     * override that happens to be stored with one.
     *
     * @throws Exception
     */
    public function testAPresetForADifferentAddressLeavesTheDefaultAlone(): void
    {
        $this->configData->setSessionTimeout(1800);
        $this->session->setUserData(new UserDto(login: 'admin'));
        $this->givenASessionTimeoutPresetFor('10.0.0.1', 120);

        $init = $this->buildInit();
        $this->markAsIndexPage($init);

        self::assertSame(1800, $this->invokePrivate($init, 'getSessionLifeTime'));
    }

    /**
     * The preset only exists serialized into the stored row, so it is put there the way the
     * application puts it there.
     *
     * @throws InvalidArgumentException
     */
    private function givenASessionTimeoutPresetFor(string $address, int $timeout): void
    {
        $this->itemPresetService
            ->method('getForCurrentUser')
            ->willReturn(
                (new ItemPreset(['type' => ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT]))
                    ->dehydrate(new SessionTimeout($address, $timeout))
            );
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
            $this->userService,
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

        // These tests sign a user in, so the disabled-account check does read the account. An
        // enabled one keeps it out of the way of what they are actually about.
        $this->userService = $this->createStub(UserService::class);
        $this->userService->method('getById')->willReturn(new User(['id' => 1, 'isDisabled' => false]));
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
