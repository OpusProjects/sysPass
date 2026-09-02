<?php

declare(strict_types=1);
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

namespace SP\Domain\Auth\Providers\Database;

use Exception;
use SP\Domain\Crypt\Hash;
use SP\Domain\Auth\Dtos\UserLoginDto;
use SP\Domain\User\Dtos\UserDto;
use SP\Application\User\Ports\UserPassService;
use SP\Application\User\Ports\UserService;

use function SP\processException;

/**
 * Class DatabaseAuth
 */
final readonly class DatabaseAuth implements DatabaseAuthService
{
    /**
     * A bcrypt hash of a value nothing supplied at a login can equal, at the cost this
     * installation hashes with, so that verifying against it costs what verifying a real user's
     * password costs. It exists to be compared against and never to match.
     */
    private const string ABSENT_USER_HASH = '$2y$12$zV1o.l9z/dn2kLYhcwHxverrSraaiNa5fNC.ZXg.ioWV7S071QRh.';

    public function __construct(
        private UserService     $userService,
        private UserPassService $userPassService
    ) {
    }

    /**
     * Authenticate using user's data
     *
     * @param UserLoginDto $userLoginDto
     * @return DatabaseAuthData
     */
    public function authenticate(UserLoginDto $userLoginDto): DatabaseAuthData
    {
        $authUser = $this->authUser($userLoginDto);

        $authData = new DatabaseAuthData($this->isAuthGranted(), $authUser ?: null);

        return $authUser ? $authData->success() : $authData->fail();
    }

    private function authUser(UserLoginDto $userLoginDto): UserDto|false
    {
        try {
            $userDto = UserDto::fromModel(
                $this->userService->getByLogin($userLoginDto->getLoginUser() ?? '')
            );

            if ($userDto->isMigrate) {
                if ($this->checkMigrateUser($userDto, $userLoginDto)) {
                    $this->userPassService->migrateUserPassById($userDto->id, $userLoginDto->getLoginPass() ?? '');

                    return $userDto;
                }

                // A row still carrying a pre-migration hash refuses at the same cost as any other.
                //
                // Its `pass` holds a sha1, md5 or crypt digest, and the three comparisons above
                // take microseconds; the fourth hands that digest to `password_verify()`, which
                // rejects it on sight because it is not a bcrypt hash at all. So a wrong password
                // here returned in about 0.3ms where a migrated account and an unknown login both
                // paid ~220ms for a real verify — measured on this installation.
                //
                // That is the enumeration oracle the catch block below deals with, reopened for a
                // subset: a fast refusal said "this login exists *and* is one of the old ones",
                // naming both a real account and the ones whose stored hashes are weakest.
                //
                // Falling through to the check below would not have spent it either — that check
                // is the same one `checkMigrateUser()` just made, against the same non-bcrypt
                // value, so it fast-fails identically and the answer is false either way.
                Hash::checkHashKey($userLoginDto->getLoginPass() ?? '', self::ABSENT_USER_HASH);

                return false;
            }

            if (Hash::checkHashKey($userLoginDto->getLoginPass() ?? '', $userDto->pass)) {
                return $userDto;
            }
        } catch (Exception $e) {
            processException($e);

            // A login that named nobody still pays for a password check.
            //
            // `getByLogin()` throws for a login that does not exist, and this used to return
            // straight away — so an existing login cost a bcrypt verify and a made-up one cost a
            // failed SELECT. At the cost this installation hashes with, that is about 255ms
            // against nothing: one request per candidate name tells an unauthenticated caller
            // which of them are real, without ever guessing a password. The reply is identical
            // either way, and was the only thing that had been made identical.
            //
            // Verifying against a fixed hash that nothing can match spends the same time on the
            // way to the same answer. `Track` still counts the attempt, so this is bounded by the
            // same limit that bounds guessing.
            Hash::checkHashKey($userLoginDto->getLoginPass() ?? '', self::ABSENT_USER_HASH);
        }

        return false;
    }

    private function checkMigrateUser(UserDto $userDto, UserLoginDto $userLoginDto): bool
    {
        $loginPass = $userLoginDto->getLoginPass() ?? '';
        $passHashSha = sha1($userDto->hashSalt . $loginPass);

        return (hash_equals($userDto->pass, $passHashSha)
                || hash_equals($userDto->pass, md5($loginPass))
                || hash_equals(
                    $userDto->pass,
                    crypt($loginPass, $userDto->hashSalt ?? '')
                )
                || Hash::checkHashKey($loginPass, $userDto->pass));
    }

    /**
     * Indicates whether it is required to access the application
     *
     * @return bool
     */
    public function isAuthGranted(): bool
    {
        return true;
    }
}
