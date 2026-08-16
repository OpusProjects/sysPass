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

namespace SP\Tests\Unit\Application\Api\Services;

use Exception;
use Faker\Factory;
use JsonException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Infrastructure\Crypt\Crypt;
use SP\Domain\Crypt\Vault;
use SP\Application\Api\Ports\ApiRequestService;
use SP\Application\Api\Services\Api;
use SP\Domain\Auth\Models\AuthToken;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\CryptException;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Application\Security\Ports\TrackService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Infrastructure\Adapter\In\Api\Controllers\Help\AccountHelp;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\Generators\UserProfileDataGenerator;
use SP\Tests\Support\UnitaryTestCase;
use stdClass;

/**
 * Class ApiServiceTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ApiTest extends UnitaryTestCase
{

    private TrackService|MockObject         $trackService;
    private ApiRequestService|MockObject    $apiRequest;
    private AuthTokenService|MockObject $authTokenService;
    private UserService|MockObject      $userService;
    private MockObject|UserProfileService $userProfileService;
    private Api                             $apiService;
    private TrackRequest                           $trackRequest;

    public static function getParamIntDataProvider(): array
    {
        $faker = Factory::create();
        $number = $faker->randomNumber();

        return [
            [$number, $number, false, true],
            [$number, $number, true, true],
            [$number, $number, true, false],
            [(string)$number, $number, false, true],
            [$faker->colorName(), null, false, true],
            [null, $faker->randomNumber(), false, true],
        ];
    }

    public static function getParamStringDataProvider(): array
    {
        $faker = Factory::create();
        $string = $faker->colorName();

        // mixed $value, mixed $expected, bool $required, bool $present
        return [
            [$string, $string, false, true],
            [$string, $string, true, true],
            [$string, $string, true, false],
            [null, null, false, true],
            [null, $faker->colorName(), false, true],
        ];
    }

    public static function getParamDataProvider(): array
    {
        $faker = Factory::create();
        $string = $faker->colorName();

        // mixed $value, mixed $expected, bool $required, bool $present
        return [
            [$string, $string, false, true],
            [$string, $string, true, true],
            [$string, $string, true, false],
            [$string, $string, false, false],
        ];
    }

    public static function getParamArrayDataProvider(): array
    {
        $faker = Factory::create();
        $numbers = array_map(fn() => $faker->randomNumber(), range(0, 4));
        $strings = array_map(fn() => $faker->colorName(), range(0, 4));

        // mixed $value, mixed $expected, bool $required, bool $present
        return [
            [$numbers, $numbers, false, true],
            [$strings, $strings, false, true],
            [$numbers, $numbers, true, true],
            [$strings, $strings, true, true],
            [$numbers, $numbers, true, false],
            [$strings, $strings, true, false],
            [$numbers, $numbers, false, false],
            [$strings, $strings, false, false],
            [null, null, false, false],
        ];
    }

    public static function getParamRawDataProvider(): array
    {
        $faker = Factory::create();
        $password = $faker->password();

        // mixed $value, mixed $expected, bool $required, bool $present
        return [
            [$password, $password, false, true],
            [$password, $password, true, true],
            [$password, $password, true, false],
            [$password, $password, false, false],
            [null, null, false, false],
        ];
    }

    /**
     * @param mixed $value
     * @param mixed $expected
     * @param bool $required
     * @param bool $present
     */
    #[DataProvider('getParamDataProvider')]
    public function testGetParam(mixed $value, mixed $expected, bool $required, bool $present)
    {
        $this->checkParam([$this->apiService, 'getParam'], ...func_get_args());
    }

    private function checkParam(
        callable $callable,
        mixed $value,
        mixed $expected,
        bool  $required,
        bool  $present
    ): void {
        $param = self::$faker->colorName();

        if ($required) {
            $this->apiRequest->expects(self::once())->method('exists')->with($param)->willReturn($present);
        }

        if (!$present) {
            $this->expectException(ServiceException::class);
            $this->expectExceptionMessage('Wrong parameters');

            $callable($param, true);
        } else {
            $this->apiRequest->expects(self::once())->method('get')->with($param)->willReturn($value);

            $out = $callable($param, $required, $expected);

            $this->assertEquals($expected, $out);
        }
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidClassException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    /**
     * "Required" meant only that the key had been sent, so `{"name": ""}` satisfied it and the API
     * created rows the web forms refuse — a category with no name is accepted here and rejected
     * there by CategoryForm::checkCommon(). Every required parameter on every endpoint comes
     * through this one method, so the gap was the whole API's, not one controller's.
     */
    #[DataProvider('emptyRequiredValueProvider')]
    public function testARequiredParameterThatSaysNothingIsRefused(mixed $value): void
    {
        $param = self::$faker->colorName();

        $this->apiRequest->method('exists')->willReturn(true);
        $this->apiRequest->method('get')->willReturn($value);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Wrong parameters');

        $this->apiService->getParam($param, true);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function emptyRequiredValueProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
        ];
    }

    /**
     * Zero and false are values, not absences.
     *
     * Refusing them would have been the easy over-reach — `empty()` treats both as nothing — and it
     * would have turned `getParamInt('id', true)` with `id=0` into a parameter error instead of
     * letting it reach the code that reports the item as not found.
     */
    #[DataProvider('falsyButPresentValueProvider')]
    public function testAFalsyValueIsStillAValue(mixed $value): void
    {
        $param = self::$faker->colorName();

        $this->apiRequest->method('exists')->willReturn(true);
        $this->apiRequest->method('get')->willReturn($value);

        self::assertSame($value, $this->apiService->getParam($param, true));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function falsyButPresentValueProvider(): array
    {
        return [
            'zero' => [0],
            'false' => [false],
            'zero string' => ['0'],
            'empty array' => [[]],
        ];
    }

    public function testGetParamWithHelp()
    {
        $apiRequest = $this->createStub(ApiRequestService::class);
        $apiRequest->method('exists')->willReturn(false);
        $apiRequest->method('getMethod')->willReturn('account/view');

        $apiService = new Api(
            $this->application,
            $this->trackService,
            $apiRequest,
            $this->authTokenService,
            $this->userService,
            $this->userProfileService
        );

        $apiService->setHelpClass(AccountHelp::class);

        try {
            $apiService->getParam(self::$faker->colorName(), true);
        } catch (ServiceException $e) {
            $this->assertNotEmpty($e->getHint());
        }
    }

    /**
     * @throws InvalidClassException
     */
    public function testSetHelpClass()
    {
        $this->apiService->setHelpClass(AccountHelp::class);

        $reflection = new ReflectionClass($this->apiService);
        $property = $reflection->getProperty('helpClass');

        $this->assertEquals(AccountHelp::class, $property->getValue($this->apiService));
    }

    /**
     * @throws InvalidClassException
     */
    public function testSetHelpClassError()
    {
        $this->expectException(InvalidClassException::class);
        $this->expectExceptionMessage('Invalid class for helper');

        $this->apiService->setHelpClass(stdClass::class);
    }

    #[DataProvider('getParamIntDataProvider')]
    public function testGetParamInt(mixed $value, mixed $expected, bool $required, bool $present)
    {
        $this->checkParam([$this->apiService, 'getParamInt'], ...func_get_args());
    }

    /**
     * @throws ServiceException
     * @throws SPException
     * @throws JsonException
     */
    public function testSetup()
    {
        $actionId = self::$faker->randomNumber(5);

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();

        $this->apiRequest->expects(self::once())->method('get')->with('authToken')->willReturn($authToken);

        $userId = self::$faker->randomNumber();

        $authTokenData = new AuthToken(['actionId' => $actionId, 'userId' => $userId]);

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiService->setup($actionId);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ContextException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->trackService = $this->createMock(TrackService::class);
        $this->apiRequest = $this->createMock(ApiRequestService::class);
        $this->authTokenService = $this->createMock(AuthTokenService::class);
        $this->userService = $this->createMock(UserService::class);
        $this->userProfileService = $this->createMock(UserProfileService::class);

        $this->trackRequest = new TrackRequest(time(), __CLASS__, self::$faker->ipv4());
        $this->trackService->method('buildTrackRequest')->willReturn($this->trackRequest);
        $this->apiRequest->method('getMethod')->willReturn(self::$faker->colorName());

        $this->apiService = new Api(
            $this->application,
            $this->trackService,
            $this->apiRequest,
            $this->authTokenService,
            $this->userService,
            $this->userProfileService
        );
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupAttemptsExceeded()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(true);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Attempts exceeded');

        $this->apiService->setup($actionId);
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupTrackingError()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(true);

        $this->trackService
            ->expects(self::once())
            ->method('add')
            ->willThrowException(new Exception());

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Internal error');

        $this->apiService->setup($actionId);
    }

    /**
     * @throws SPException
     */
    public function testSetupInvalidToken()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $this->apiRequest
            ->expects(self::once())
            ->method('get')
            ->with('authToken')
            ->willThrowException(new NoSuchItemException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Internal error');

        $this->apiService->setup($actionId);
    }

    /**
     * A token nobody issued is the attempt the counter exists for: guessing token values is the
     * only way into the API from outside.
     *
     * It was the one failure that did not count. A request omitting the token entirely did — and
     * that is not what anybody brute-forcing sends — so the limit checked at the top of setup()
     * was unreachable by the attack it guards against.
     *
     * @throws ServiceException
     * @throws SPException
     */
    #[Test]
    public function aTokenThatWasNeverIssuedCountsAsAnAttempt()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService->method('checkTracking')->willReturn(false);
        $this->apiRequest->method('get')->willReturn(self::$faker->password());

        $this->authTokenService
            ->method('getTokenByToken')
            ->willThrowException(new NoSuchItemException('not found'));

        $this->trackService
            ->expects(self::once())
            ->method('add')
            ->with($this->trackRequest);

        $this->expectException(ServiceException::class);

        $this->apiService->setup($actionId);
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupAccessDenied()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();

        $this->apiRequest->expects(self::once())->method('get')->with('authToken')->willReturn($authToken);

        $userId = self::$faker->randomNumber();

        $authTokenData = new AuthToken(['actionId' => self::$faker->randomNumber(), 'userId' => $userId]);

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $this->trackService->expects(self::once())->method('add')->with($this->trackRequest);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Unauthorized access');

        $this->apiService->setup($actionId);
    }

    /**
     * A request that names no token at all (rather than a wrong or expired one) must be refused
     * the same way: before this was covered, the null check on line 117 was reachable code that
     * nothing exercised, so a request missing the `authToken` parameter entirely was only
     * assumed to be refused, never actually proven to be.
     *
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupMissingToken()
    {
        $actionId = self::$faker->randomNumber();

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $this->apiRequest->expects(self::once())->method('get')->with('authToken')->willReturn(null);

        // A missing token never reaches the token lookup at all.
        $this->authTokenService->expects(self::never())->method('getTokenByToken');

        $this->trackService->expects(self::once())->method('add')->with($this->trackRequest);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Unauthorized access');

        $this->apiService->setup($actionId);
    }

    /**
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupWithMasterPass()
    {
        $actionId = AclActionsInterface::ACCOUNT_VIEW_PASS;

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        $this->apiRequest->expects(self::exactly(3))
                         ->method('get')
                         ->willReturnOnConsecutiveCalls($authToken, $authToken, $authToken);

        $vaultKey = $authToken . $authToken;

        $vault = Vault::factory(new Crypt())->saveData(self::$faker->password(), $vaultKey);

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => serialize($vault)]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->apiService->setup($actionId);
    }

    /**
     * @throws CryptException
     * @throws SPException
     * @throws ServiceException
     * @throws JsonException
     */
    public function testSetupWithMasterPassWrongTokenPass()
    {
        $actionId = AclActionsInterface::ACCOUNT_VIEW_PASS;

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        $this->apiRequest->expects(self::exactly(3))
                         ->method('get')
                         ->willReturnOnConsecutiveCalls($authToken, $authToken, $authToken);

        $vault = Vault::factory(new Crypt())->saveData(self::$faker->password(), sha1(self::$faker->password()));

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => serialize($vault)]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Internal error');

        $this->apiService->setup($actionId);
    }

    /**
     * A token that passed every earlier check (valid, issued for this action, correct
     * `tokenPass` hash) but was persisted without a vault at all cannot yield a master
     * password — there is nothing to decrypt. This is a distinct failure from a wrong
     * `tokenPass` (caught earlier by the hash check) or a wrong vault key (caught by the
     * decrypt failing below): here the token record itself is incomplete, and that has to be
     * refused rather than fall through to a null master password being handed out.
     *
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupWithMasterPassMissingVault()
    {
        $actionId = AclActionsInterface::ACCOUNT_VIEW_PASS;

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        // Only 'authToken' (setup) and 'tokenPass' (the hash check) are read; the missing
        // vault is caught before a third call would build the decryption key.
        $this->apiRequest->expects(self::exactly(2))
                         ->method('get')
                         ->willReturnOnConsecutiveCalls($authToken, $authToken);

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => null]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Internal error');

        $this->apiService->setup($actionId);
    }

    /**
     * A vault that decrypts cleanly but yields an empty master password must be refused the
     * same as a decryption failure — `Vault::getData()` only throws on a wrong key, so an
     * empty stored value is the one way the vault lookup can fail silently (falsy, no
     * exception) rather than loudly. Without this check, an empty string would have been
     * handed back to the caller as a "valid" master password.
     *
     * @throws CryptException
     * @throws ServiceException
     * @throws SPException
     */
    public function testSetupWithMasterPassEmptyVaultData()
    {
        $actionId = AclActionsInterface::ACCOUNT_VIEW_PASS;

        $this->trackService
            ->expects(self::once())
            ->method('checkTracking')
            ->with($this->trackRequest)
            ->willReturn(false);

        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        $this->apiRequest->expects(self::exactly(3))
                         ->method('get')
                         ->willReturnOnConsecutiveCalls($authToken, $authToken, $authToken);

        $vaultKey = $authToken . $authToken;

        // The key matches, so decryption succeeds — it is the decrypted value itself that is
        // empty, which is what forces the fallthrough to "Internal error" rather than
        // returning a usable (if wrong) master password.
        $vault = Vault::factory(new Crypt())->saveData('', $vaultKey);

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => serialize($vault)]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Internal error');

        $this->apiService->setup($actionId);
    }

    #[DataProvider('getParamStringDataProvider')]
    public function testGetParamString(mixed $value, mixed $expected, bool $required, bool $present): void
    {
        $this->checkParam([$this->apiService, 'getParamString'], ...func_get_args());
    }

    #[DataProvider('getParamArrayDataProvider')]
    public function testGetParamArray(mixed $value, mixed $expected, bool $required, bool $present)
    {
        $this->checkParam([$this->apiService, 'getParamArray'], ...func_get_args());
    }

    /**
     * A JSON-RPC client sending a scalar where an array is expected (e.g. "tagsId": 5
     * instead of [5]) must be rejected as a client error, not blow up with a TypeError
     * when the scalar reaches Filter::getArray().
     */
    public function testGetParamArrayWithScalarValue()
    {
        $param = self::$faker->colorName();

        $this->apiRequest
            ->expects(self::once())
            ->method('get')
            ->with($param)
            ->willReturn(self::$faker->randomNumber());

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Wrong parameters');

        $this->apiService->getParamArray($param);
    }

    /**
     * An optional array parameter the caller simply did not send (the underlying value is
     * null, not present-but-wrong) must come back as null rather than an empty array — a
     * caller distinguishing "no tags were sent" from "tags were cleared" depends on that.
     *
     * @throws ServiceException
     */
    public function testGetParamArrayReturnsNullWhenAbsent()
    {
        $param = self::$faker->colorName();

        $this->apiRequest->expects(self::once())->method('get')->with($param)->willReturn(null);

        $this->assertNull($this->apiService->getParamArray($param));
    }

    #[DataProvider('getParamRawDataProvider')]
    public function testGetParamRaw(mixed $value, mixed $expected, bool $required, bool $present)
    {
        $this->checkParam([$this->apiService, 'getParamRaw'], ...func_get_args());
    }

    /**
     * An optional raw parameter that was not sent falls back to the caller's own default
     * rather than null, so a caller relying on `getParamRaw($p, false, 'foo')` gets 'foo'
     * back instead of having to null-check every optional field itself.
     *
     * @throws ServiceException
     */
    public function testGetParamRawReturnsDefaultWhenAbsent()
    {
        $param = self::$faker->colorName();
        $default = self::$faker->password();

        $this->apiRequest->expects(self::once())->method('get')->with($param)->willReturn(null);

        $this->assertSame($default, $this->apiService->getParamRaw($param, false, $default));
    }

    public function testGetRequestId()
    {
        $this->assertEquals($this->apiRequest->getId(), $this->apiService->getRequestId());
    }

    /**
     * @throws ContextException
     * @throws CryptException
     * @throws ServiceException
     * @throws SPException
     */
    public function testRequireMasterPass()
    {
        $actionId = self::$faker->randomNumber(4);
        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        $this->apiRequest->expects(self::exactly(3))
                         ->method('get')
                         ->willReturn($authToken);

        $vaultKey = $authToken . $authToken;

        $masterPass = self::$faker->password();

        $vault = Vault::factory(new Crypt())->saveData($masterPass, $vaultKey);

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => serialize($vault)]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->apiService->setup($actionId);
        $this->apiService->requireMasterPass();

        $this->assertEquals($masterPass, $this->context->getTrasientKey(Context::MASTER_PASSWORD_KEY));
    }

    /**
     * @throws ContextException
     * @throws ServiceException
     * @throws SPException
     */
    public function testRequireMasterPassNotInitialized()
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('API not initialized');

        $this->apiService->requireMasterPass();
    }

    /**
     * @throws ServiceException
     * @throws CryptException
     * @throws SPException
     */
    public function testGetMasterPass()
    {
        $actionId = AclActionsInterface::ACCOUNT_VIEW_PASS;
        $authToken = self::$faker->password();
        $authTokenHash = password_hash($authToken, PASSWORD_BCRYPT);

        $this->apiRequest->expects(self::exactly(3))
                         ->method('get')
                         ->willReturn($authToken);

        $vaultKey = $authToken . $authToken;

        $masterPass = self::$faker->password();

        $vault = Vault::factory(new Crypt())->saveData($masterPass, $vaultKey);

        $userId = self::$faker->randomNumber();

        $authTokenData =
            new AuthToken(
                ['actionId' => $actionId, 'userId' => $userId, 'hash' => $authTokenHash, 'vault' => serialize($vault)]
            );

        $this->authTokenService
            ->expects(self::once())
            ->method('getTokenByToken')
            ->with($actionId, $authToken)
            ->willReturn($authTokenData);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['isDisabled' => false]);

        $this->userService->expects(self::once())->method('getById')->with($userId)->willReturn($userData);
        $this->userProfileService->expects(self::once())
                                 ->method('getById')
                                 ->with($userData->getUserProfileId())
                                 ->willReturn(UserProfileDataGenerator::factory()->buildUserProfileData());

        $this->apiRequest->expects(self::once())->method('exists')->with('tokenPass')->willReturn(true);

        $this->apiService->setup($actionId);

        $this->assertEquals(
            $this->apiService->getMasterPass(),
            $this->context->getTrasientKey(Context::MASTER_PASSWORD_KEY)
        );
    }
}
