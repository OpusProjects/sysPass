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

namespace SP\Tests\Unit\Application\Auth\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Application\Application;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Domain\Auth\Dtos\LoginResponseDto;
use SP\Domain\Auth\Dtos\UserLoginDto;
use SP\Application\Auth\Ports\LoginAuthHandlerService;
use SP\Application\Auth\Ports\LoginMasterPassService;
use SP\Application\Auth\Ports\LoginUserService;
use SP\Domain\Auth\Providers\AuthDataBase;
use SP\Domain\Auth\Providers\AuthProviderService;
use SP\Domain\Auth\Providers\AuthResult;
use SP\Domain\Auth\Providers\AuthType;
use SP\Domain\Auth\Providers\Browser\BrowserAuthData;
use SP\Domain\Auth\Providers\Database\DatabaseAuthData;
use SP\Domain\Auth\Providers\Ldap\LdapAuthData;
use SP\Domain\Auth\Services\AuthException;
use SP\Application\Auth\Services\Login;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Crypt\Ports\SessionKeyService;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\Log\Ports\ProviderInterface;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Application\Security\Ports\TrackService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\Generators\UserProfileDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class LoginTest
 *
 * @property SessionContext|MockObject $context
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
#[RunClassInSeparateProcess]
class LoginTest extends UnitaryTestCase
{

    private TrackService|MockObject                          $trackService;
    private RequestService|MockObject $request;
    private MockObject|AuthProviderService|ProviderInterface $authProviderService;
    private MockObject|LanguageInterface                     $language;
    private UserService|MockObject                           $userService;
    private LoginUserService|MockObject                      $loginUserService;
    private MockObject|LoginMasterPassService                $loginMasterPassService;
    private MockObject|UserProfileService                    $userProfileService;
    private MockObject|LoginAuthHandlerService               $loginAuthHandlerService;
    private Login                                            $login;

    public static function authResultProviderAuthoritative(): array
    {
        $authResultDatabase = new AuthResult(AuthType::Database, (new DatabaseAuthData(true))->success());
        $authResultBrowser = new AuthResult(AuthType::Browser, (new BrowserAuthData(true))->success());
        $authResultLdap = new AuthResult(AuthType::Ldap, (new LdapAuthData(true))->success());

        return array_map(
            static fn(AuthResult $authResult) => [
                $authResult,
                $authResult->getAuthData(),
                $authResult->getAuthType()->value
            ],
            [
                $authResultDatabase,
                $authResultBrowser,
                $authResultLdap
            ]
        );
    }

    public static function authResultProviderNonAuthoritative(): array
    {
        $authResultDatabase = new AuthResult(AuthType::Database, (new DatabaseAuthData(false))->success());
        $authResultBrowser = new AuthResult(AuthType::Browser, (new BrowserAuthData(false))->success());
        $authResultLdap = new AuthResult(AuthType::Ldap, (new LdapAuthData(false))->success());

        return array_map(
            static fn(AuthResult $authResult) => [
                $authResult,
                $authResult->getAuthData(),
                $authResult->getAuthType()->value
            ],
            [
                $authResultDatabase,
                $authResultBrowser,
                $authResultLdap
            ]
        );
    }

    public static function loginInputProvider(): array
    {
        return [
            ['', 'a_pass'],
            ['a_user', '']
        ];
    }

    public static function loginStatusDataProvider(): array
    {
        return [
            [LoginStatus::INVALID_LOGIN],
            [LoginStatus::PASS_RESET_REQUIRED],
            [LoginStatus::USER_DISABLED],
            [LoginStatus::MAX_ATTEMPTS_EXCEEDED],
            [LoginStatus::INVALID_MASTER_PASS],
            [LoginStatus::OLD_PASS_REQUIRED],
            [LoginStatus::OK],
        ];
    }

    public static function fromDataProvider(): array
    {
        return [
            [null, 'index.php?r=index'],
            ['a_test', 'index.php?r=a_test'],
        ];
    }

    /**
     * @throws AuthException
     */
    #[DataProvider('authResultProviderAuthoritative')]
    #[DataProvider('authResultProviderNonAuthoritative')]
    public function testHandleAuthResponseWithTrue(
        AuthResult   $authResult,
        AuthDataBase $authDataBase,
        string       $targetMethod
    ) {
        $this->loginAuthHandlerService
            ->expects($this->once())
            ->method($targetMethod)
            ->with($authDataBase);

        $this->login->handleAuthResponse($authResult);
    }

    /**
     * @throws AuthException
     */
    #[DataProvider('authResultProviderAuthoritative')]
    #[DataProvider('authResultProviderNonAuthoritative')]
    public function testHandleAuthResponseWithFalse(
        AuthResult   $authResult,
        AuthDataBase $authDataBase,
        string       $targetMethod
    ) {
        $this->loginAuthHandlerService
            ->expects($this->once())
            ->method($targetMethod)
            ->with($authDataBase);

        $this->login->handleAuthResponse($authResult);
    }

    /**
     * @param string|null $from
     * @param string $redirect
     * @throws AuthException
     * @throws SPException
     */
    #[DataProvider('fromDataProvider')]
    public function testDoLogin(?string $from, string $redirect)
    {
        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->with(
                self::callback(function (UserLoginDto $userLoginDto) {
                    return $userLoginDto->getLoginUser() === 'a_user'
                           && $userLoginDto->getLoginPass() === 'a_password';
                }),
                self::callback(function (array $callable) {
                    return $callable[0] instanceof Login
                           && $callable[1] === 'handleAuthResponse';
                })
            )
            ->willReturn($userDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->with($userDto)
            ->willReturn(new LoginResponseDto(LoginStatus::PASS));

        $this->loginMasterPassService
            ->expects($this->once())
            ->method('loadMasterPass')
            ->with(
                self::callback(function (UserLoginDto $userLoginDto) {
                    return $userLoginDto->getLoginUser() === 'a_user'
                           && $userLoginDto->getLoginPass() === 'a_password';
                }),
                $userDto
            );

        $this->userService
            ->expects($this->once())
            ->method('updateLastLoginById')
            ->with($userDto->id);

        $this->context
            ->expects($this->once())
            ->method('setUserData')
            ->with($userDto);

        $userProfile = UserProfileDataGenerator::factory()->buildUserProfileData();

        $this->userProfileService
            ->expects($this->once())
            ->method('getById')
            ->with($userDto->userProfileId)
            ->willReturn($userProfile);

        $this->context
            ->expects($this->once())
            ->method('setUserProfile')
            ->with($userProfile->hydrate(ProfileData::class));

        $this->language
            ->expects($this->once())
            ->method('setLanguage')
            ->with(true);

        $this->context
            ->expects($this->once())
            ->method('setAuthCompleted')
            ->with(true);

        $out = $this->login->doLogin($from);

        $this->assertEquals(LoginStatus::OK, $out->getStatus());
        $this->assertEquals($redirect, $out->getRedirect());
    }

    /**
     * @throws AuthException
     */
    #[DataProvider('loginInputProvider')]
    public function testDoLoginWithEmptyUserOrPass(string $user, string $pass)
    {
        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn($user);

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn($pass);

        $this->trackService
            ->expects($this->once())
            ->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Wrong login');

        $this->login->doLogin();
    }

    public function testDoLoginWithNullUserData()
    {
        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->with(
                self::callback(function (UserLoginDto $userLoginDto) {
                    return $userLoginDto->getLoginUser() === 'a_user'
                           && $userLoginDto->getLoginPass() === 'a_password';
                }),
                self::callback(function (array $callable) {
                    return $callable[0] instanceof Login
                           && $callable[1] === 'handleAuthResponse';
                })
            )
            ->willReturn(null);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Internal error');

        $this->login->doLogin();
    }

    /**
     * @throws AuthException
     * @throws SPException
     */
    #[DataProvider('loginStatusDataProvider')]
    public function testDoLoginWithCheckUserFail(LoginStatus $loginStatus)
    {
        $userDataDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->with(
                self::callback(function (UserLoginDto $userLoginDto) {
                    return $userLoginDto->getLoginUser() === 'a_user'
                           && $userLoginDto->getLoginPass() === 'a_password';
                }),
                self::callback(function (array $callable) {
                    return $callable[0] instanceof Login
                           && $callable[1] === 'handleAuthResponse';
                })
            )
            ->willReturn($userDataDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->with($userDataDto)
            ->willReturn(new LoginResponseDto($loginStatus));

        $out = $this->login->doLogin();

        $this->assertEquals($loginStatus, $out->getStatus());
    }

    /**
     * @throws AuthException
     */
    public function testDoLoginWithServiceException()
    {
        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willThrowException(ServiceException::error('test'));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('test');

        $this->login->doLogin();
    }

    /**
     * @throws AuthException
     */
    public function testDoLoginWithException()
    {
        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willThrowException(new RuntimeException('test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test');

        $this->login->doLogin();
    }

    /**
     * PHP's session ID must be rotated the moment a user authenticates, or an attacker who
     * fixated the pre-login session ID (e.g. via a shared/public kiosk) keeps a valid
     * session after the victim logs in. No SessionKeyService is wired for the shared
     * $this->login built in setUp() (mirrors the real DI gap this app has where the
     * optional constructor param is never bound unless explicitly wired), so the plain
     * session_regenerate_id() fallback is what actually runs.
     *
     * @throws AuthException
     * @throws SPException
     */
    public function testDoLoginRegeneratesTheSessionIdWhenNoSessionKeyServiceIsWired()
    {
        session_start();
        $sessionIdBeforeLogin = session_id();

        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willReturn($userDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->willReturn(new LoginResponseDto(LoginStatus::PASS));

        $this->loginMasterPassService->expects($this->once())->method('loadMasterPass');

        $this->userService
            ->expects($this->once())
            ->method('updateLastLoginById')
            ->with($userDto->id);

        $this->userProfileService
            ->expects($this->once())
            ->method('getById')
            ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->language->expects($this->once())->method('setLanguage')->with(true);

        $out = $this->login->doLogin();

        $this->assertEquals(LoginStatus::OK, $out->getStatus());
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotEquals($sessionIdBeforeLogin, session_id());
    }

    /**
     * When a SessionKeyService IS wired (the production DI path, once the optional
     * constructor param is explicitly bound), a successful login must re-key the session
     * through that service instead of a bare id regeneration — reKey() rotates the id
     * while re-encrypting the vault under the new seed, so skipping it would leave the
     * vault keyed to a session id that no longer exists.
     *
     * @throws AuthException
     * @throws SPException
     * @throws Exception
     */
    public function testDoLoginReKeysTheSessionWhenASessionKeyServiceIsWired()
    {
        $sessionKeyService = $this->createMock(SessionKeyService::class);
        $sessionKeyService->expects($this->once())->method('reKey')->with($this->context);

        $login = $this->buildLoginWithSessionWiring($this->context, $sessionKeyService);

        session_start();

        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willReturn($userDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->willReturn(new LoginResponseDto(LoginStatus::PASS));

        $this->loginMasterPassService->expects($this->once())->method('loadMasterPass');

        $this->userService
            ->expects($this->once())
            ->method('updateLastLoginById')
            ->with($userDto->id);

        $this->userProfileService
            ->expects($this->once())
            ->method('getById')
            ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->language->expects($this->once())->method('setLanguage')->with(true);

        $out = $login->doLogin();

        $this->assertEquals(LoginStatus::OK, $out->getStatus());
    }

    /**
     * If re-keying itself fails (e.g. the vault could not be re-encrypted under the
     * rotated seed), login must not blow up for a user who just typed the right password —
     * it logs the failure and falls back to a bare session_regenerate_id() so the session
     * id is still rotated even though the vault re-key did not happen.
     *
     * @throws AuthException
     * @throws SPException
     * @throws Exception
     */
    public function testDoLoginFallsBackToRegeneratingTheSessionIdWhenReKeyingFails()
    {
        $sessionKeyService = $this->createMock(SessionKeyService::class);
        $sessionKeyService->expects($this->once())
            ->method('reKey')
            ->with($this->context)
            ->willThrowException(CryptException::error('vault re-encryption failed'));

        $login = $this->buildLoginWithSessionWiring($this->context, $sessionKeyService);

        session_start();
        $sessionIdBeforeLogin = session_id();

        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willReturn($userDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->willReturn(new LoginResponseDto(LoginStatus::PASS));

        $this->loginMasterPassService->expects($this->once())->method('loadMasterPass');

        $this->userService
            ->expects($this->once())
            ->method('updateLastLoginById')
            ->with($userDto->id);

        $this->userProfileService
            ->expects($this->once())
            ->method('getById')
            ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->language->expects($this->once())->method('setLanguage')->with(true);

        $out = $login->doLogin();

        $this->assertEquals(LoginStatus::OK, $out->getStatus());
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotEquals($sessionIdBeforeLogin, session_id());
    }

    /**
     * setUserSession() wraps the user-service/profile-service/context calls in one try
     * block specifically so a ConstraintException, QueryException or NoSuchItemException
     * from any of them collapses into the same ServiceException doLogin() already knows
     * how to turn into an AuthException. Without this, a user profile that vanished
     * between authentication and session setup would leak a raw domain exception out of
     * what looks to the caller like a successful login.
     *
     * @throws AuthException
     */
    public function testDoLoginWrapsANoSuchItemExceptionFromSetUserSessionAsAuthException()
    {
        $userDto = UserDto::fromModel(UserDataGenerator::factory()->buildUserData());

        $this->request
            ->expects($this->once())
            ->method('analyzeString')
            ->with('user')
            ->willReturn('a_user');

        $this->request
            ->expects($this->once())
            ->method('analyzeEncrypted')
            ->with('pass')
            ->willReturn('a_password');

        $this->trackService
            ->expects($this->once())
            ->method('checkTracking')
            ->willReturn(false);

        $this->authProviderService
            ->expects($this->once())
            ->method('doAuth')
            ->willReturn($userDto);

        $this->loginUserService
            ->expects($this->once())
            ->method('checkUser')
            ->willReturn(new LoginResponseDto(LoginStatus::PASS));

        $this->loginMasterPassService->expects($this->once())->method('loadMasterPass');

        $this->userService
            ->expects($this->once())
            ->method('updateLastLoginById')
            ->with($userDto->id);

        $this->userProfileService
            ->expects($this->once())
            ->method('getById')
            ->willThrowException(new NoSuchItemException('Profile not found'));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Profile not found');

        $this->login->doLogin();
    }

    /**
     * Builds a Login wired to a dedicated Application and TrackService, so a single test
     * can vary the SessionKeyService it runs under without disturbing the shared
     * $this->login / $this->trackService the rest of the suite relies on — buildTrackRequest()
     * on $this->trackService is already tied to a once() expectation spent constructing
     * $this->login in setUp().
     *
     * @throws Exception
     */
    private function buildLoginWithSessionWiring(Context $context, ?SessionKeyService $sessionKeyService): Login
    {
        $trackService = $this->createStub(TrackService::class);
        $trackService->method('buildTrackRequest')->willReturn(
            new TrackRequest(
                self::$faker->unixTime(),
                self::$faker->colorName(),
                self::$faker->ipv4(),
                self::$faker->randomNumber(2)
            )
        );
        $trackService->method('checkTracking')->willReturn(false);

        $application = new Application(
            $this->config,
            $this->createStub(EventDispatcherInterface::class),
            $context,
            $sessionKeyService
        );

        return new Login(
            $application,
            $trackService,
            $this->request,
            $this->authProviderService,
            $this->language,
            $this->userService,
            $this->loginUserService,
            $this->loginMasterPassService,
            $this->userProfileService,
            $this->loginAuthHandlerService
        );
    }

    /**
     * @throws Exception
     */
    protected function buildContext(): Context
    {
        return $this->createMock(SessionContext::class);
    }

    /**
     * @throws ContextException
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws SPException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->trackService = $this->createMock(TrackService::class);
        $this->trackService
            ->expects($this->once())
            ->method('buildTrackRequest')
            ->with(Login::class)
            ->willReturn(
                new TrackRequest(
                    self::$faker->unixTime(),
                    self::$faker->colorName(),
                    self::$faker->ipv4(),
                    self::$faker->randomNumber(2)
                )
            );

        $this->request = $this->createMock(RequestService::class);
        $this->authProviderService = $this->createMockForIntersectionOfInterfaces(
            [AuthProviderService::class, ProviderInterface::class]
        );

        $this->language = $this->createMock(LanguageInterface::class);
        $this->userService = $this->createMock(UserService::class);
        $this->loginUserService = $this->createMock(LoginUserService::class);
        $this->loginMasterPassService = $this->createMock(LoginMasterPassService::class);
        $this->userProfileService = $this->createMock(UserProfileService::class);
        $this->loginAuthHandlerService = $this->createMock(LoginAuthHandlerService::class);

        $this->login = new Login(
            $this->application,
            $this->trackService,
            $this->request,
            $this->authProviderService,
            $this->language,
            $this->userService,
            $this->loginUserService,
            $this->loginMasterPassService,
            $this->userProfileService,
            $this->loginAuthHandlerService
        );
    }

    /**
     * Several tests above start a real PHP session to exercise setUserSession()'s id
     * rotation. This class runs in its own process (#[RunClassInSeparateProcess]), so a
     * session left open here would otherwise leak into the next test method run in that
     * same process.
     */
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];

        parent::tearDown();
    }
}
