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

namespace SP\Tests\Unit\Domain\Auth\Providers\Database;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Crypt\Hash;
use SP\Domain\Auth\Dtos\UserLoginDto;
use SP\Domain\Auth\Providers\Database\DatabaseAuth;
use SP\Application\User\Ports\UserPassService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class DatabaseAuthTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DatabaseAuthTest extends UnitaryTestCase
{

    private UserService|MockObject     $userService;
    private MockObject|UserPassService $userPassService;
    private DatabaseAuth                    $databaseAuth;

    public function testAuthenticate()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();
        $hashedPass = Hash::hashKey($pass);

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['login' => $user, 'pass' => $hashedPass]);

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertTrue($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testAuthenticateWithWrongLogin()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willThrowException(new NoSuchItemException('User does not exist'));

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertFalse($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testAuthenticateWithWrongPass()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(['login' => $user]);

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertFalse($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    /**
     * A login naming nobody costs what a login naming somebody costs.
     *
     * `getByLogin()` throws for a login that does not exist, and this used to return straight
     * away — so a real login cost a bcrypt verify and a made-up one cost a failed SELECT. At the
     * cost this installation hashes with that is roughly 250ms against nothing, which tells an
     * unauthenticated caller which names are real without their ever guessing a password. The
     * refusal itself was already identical for both, and was the only thing that had been.
     *
     * Measured against each other rather than against a fixed number of milliseconds, so the
     * assertion calibrates itself to whatever machine it runs on: both paths do one bcrypt, so
     * the ratio is about one, and half of it is a floor no unhashed path can reach. Without the
     * work the missing-user path takes well under a thousandth of the other.
     *
     * The known-user fixture is given a *real* hash on purpose: `UserDataGenerator` puts a plain
     * string in `pass`, which `password_verify()` rejects as malformed and returns from at once,
     * so a comparison against that would be two fast paths agreeing and would prove nothing.
     */
    public function testALoginForAMissingUserCostsTheSameAsOneForAnExistingUser(): void
    {
        $password = self::$faker->password();

        $existing = UserDataGenerator::factory()->buildUserData()->mutate(
            ['login' => 'a-real-login', 'pass' => Hash::hashKey('the-actual-password')]
        );

        $this->userService
            ->method('getByLogin')
            ->willReturnCallback(
                static function (string $login) use ($existing) {
                    if ($login === 'a-real-login') {
                        return $existing;
                    }

                    throw new NoSuchItemException('User does not exist');
                }
            );

        $costOfExistingUser = $this->timeAuthenticating('a-real-login', $password);
        $costOfMissingUser = $this->timeAuthenticating('no-such-login', $password);

        self::assertGreaterThan(
            $costOfExistingUser / 2,
            $costOfMissingUser,
            sprintf(
                'a login for a user that does not exist has to cost about what one for a user that'
                . ' does costs, or the difference tells a caller which logins are real'
                . ' (existing: %.1fms, missing: %.1fms)',
                $costOfExistingUser * 1000,
                $costOfMissingUser * 1000
            )
        );
    }

    /**
     * Seconds spent on one authentication attempt, which must fail either way.
     */
    private function timeAuthenticating(string $login, string $password): float
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($login);
        $userLoginData->setLoginPass($password);

        $started = microtime(true);
        $result = $this->databaseAuth->authenticate($userLoginData);
        $elapsed = microtime(true) - $started;

        self::assertFalse($result->isOk(), $login . ': the attempt has to fail');

        return $elapsed;
    }


    public function testAuthenticateWithMigrationBySHA1()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();

        $userData = UserDataGenerator::factory()
                                     ->buildUserData()
                                     ->mutate(
                                         [
                                             'login' => $user,
                                             'pass' => md5($pass),
                                             'isMigrate' => true
                                         ]
                                     );

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $this->userPassService
            ->expects(self::once())
            ->method('migrateUserPassById')
            ->with($userData->getId(), $pass);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertTrue($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testAuthenticateWithMigrationByMD5()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();
        $salt = self::$faker->password();

        $userData = UserDataGenerator::factory()
                                     ->buildUserData()
                                     ->mutate(
                                         [
                                             'login' => $user,
                                             'pass' => sha1($salt . $pass),
                                             'hashSalt' => $salt,
                                             'isMigrate' => true
                                         ]
                                     );

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $this->userPassService
            ->expects(self::once())
            ->method('migrateUserPassById')
            ->with($userData->getId(), $pass);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertTrue($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testAuthenticateWithMigrationByCrypt()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();
        $salt = self::$faker->password();

        $userData = UserDataGenerator::factory()
                                     ->buildUserData()
                                     ->mutate(
                                         [
                                             'login' => $user,
                                             'pass' => crypt($pass, $salt),
                                             'hashSalt' => $salt,
                                             'isMigrate' => true
                                         ]
                                     );

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $this->userPassService
            ->expects(self::once())
            ->method('migrateUserPassById')
            ->with($userData->getId(), $pass);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertTrue($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testAuthenticateWithMigrationByHash()
    {
        $user = self::$faker->userName();
        $pass = self::$faker->password();
        $hashedPass = Hash::hashKey($pass);

        $userData = UserDataGenerator::factory()
                                     ->buildUserData()
                                     ->mutate(
                                         [
                                             'login' => $user,
                                             'pass' => $hashedPass,
                                             'isMigrate' => true
                                         ]
                                     );

        $this->userService
            ->expects(self::once())
            ->method('getByLogin')
            ->with($user)
            ->willReturn($userData);

        $this->userPassService
            ->expects(self::once())
            ->method('migrateUserPassById')
            ->with($userData->getId(), $pass);

        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser($user);
        $userLoginData->setLoginPass($pass);

        self::assertTrue($this->databaseAuth->authenticate($userLoginData)->isOk());
    }

    public function testIsAuthGranted()
    {
        self::assertTrue($this->databaseAuth->isAuthGranted());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->userService = $this->createMock(UserService::class);
        $this->userPassService = $this->createMock(UserPassService::class);

        $this->databaseAuth = new DatabaseAuth($this->userService, $this->userPassService);
    }

}
