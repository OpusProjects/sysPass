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

namespace SP\Tests\Application\Account\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Domain\Account\Dtos\EncryptedPassword;
use SP\Application\Account\Ports\AccountCryptService;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Services\AccountMasterPassword;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Crypt\Dtos\UpdateMasterPassRequest;
use SP\Tests\Generators\AccountDataGenerator;
use SP\Tests\UnitaryTestCase;

/**
 * Class AccountMasterPasswordTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class AccountMasterPasswordTest extends UnitaryTestCase
{
    private MockObject|AccountService        $account;
    private MockObject|AccountHistoryService $accountHistory;
    private AccountMasterPassword            $accountMasterPassword;
    private MockObject|CryptInterface        $crypt;
    private MockObject|AccountCryptService   $accountCrypt;

    /**
     * @throws ServiceException
     */
    public function testUpdateMasterPassword(): void
    {
        $request =
            new UpdateMasterPassRequest(
                self::$faker->password(),
                self::$faker->password(),
                self::$faker->sha1()
            );
        $accountData = array_map(static fn() => AccountDataGenerator::factory()->buildAccount(), range(0, 9));

        $this->account->expects(self::once())
                      ->method('getAccountsPassData')
                      ->willReturn($accountData);
        $this->accountCrypt->expects(self::exactly(10))
                           ->method('getPasswordEncrypted')
                           ->willReturn(new EncryptedPassword('a_password', 'a_key'));
        $this->account->expects(self::exactly(10))
                      ->method('updatePasswordMasterPass')
                      ->with(self::anything(), new EncryptedPassword('a_password', 'a_key'));

        $this->accountMasterPassword->updateMasterPassword($request);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdateMasterPasswordWithNoAccounts(): void
    {
        $request =
            new UpdateMasterPassRequest(
                self::$faker->password(),
                self::$faker->password(),
                self::$faker->sha1()
            );

        $this->account->expects(self::once())
                      ->method('getAccountsPassData')
                      ->willReturn([]);
        $this->account->expects(self::never())
                      ->method('updatePasswordMasterPass');
        $this->crypt->expects(self::never())
                    ->method('decrypt');
        $this->crypt->expects(self::never())
                    ->method('makeSecuredKey');
        $this->crypt->expects(self::never())
                    ->method('encrypt');

        $this->accountMasterPassword->updateMasterPassword($request);
    }

    /**
     * A partial re-key failure (some accounts' decrypt/re-encrypt throws) must cause
     * updateMasterPassword to throw ServiceException so the transaction rolls back and
     * the config master-pass hash is never advanced.
     *
     * @throws ServiceException
     */
    public function testUpdateMasterPasswordThrowsServiceExceptionOnPartialError(): void
    {
        $request = new UpdateMasterPassRequest(self::$faker->password(), self::$faker->password(), self::$faker->sha1());
        $accountData = array_map(static fn() => AccountDataGenerator::factory()->buildAccount(), range(0, 9));

        $this->account->expects(self::once())
                      ->method('getAccountsPassData')
                      ->willReturn($accountData);
        $this->crypt->expects(self::exactly(10))
                    ->method('decrypt')
                    ->willThrowException(new SPException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessageMatches('/could not be re-encrypted/');

        $this->accountMasterPassword->updateMasterPassword($request);
    }

    /**
     * Regression: mixed success/failure — 5 accounts re-encrypt fine, 5 fail.
     * The method must throw ServiceException (so the transaction rolls back)
     * and must NOT silently swallow errors and return normally.
     *
     * @throws ServiceException
     */
    public function testUpdateMasterPasswordAbortedOnPartialError(): void
    {
        $request = new UpdateMasterPassRequest(self::$faker->password(), self::$faker->password(), self::$faker->sha1());
        $accountData = array_map(static fn() => AccountDataGenerator::factory()->buildAccount(), range(0, 9));

        $this->account->expects(self::once())
                      ->method('getAccountsPassData')
                      ->willReturn($accountData);

        // First 5 succeed, last 5 fail
        $this->crypt->expects(self::exactly(10))
                    ->method('decrypt')
                    ->willReturnCallback(static function () {
                        static $call = 0;
                        $call++;
                        if ($call > 5) {
                            throw new SPException('decrypt failed');
                        }
                        return 'decrypted';
                    });

        $this->accountCrypt->expects(self::exactly(5))
                           ->method('getPasswordEncrypted')
                           ->willReturn(new EncryptedPassword('a_password', 'a_key'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessageMatches('/could not be re-encrypted/');

        $this->accountMasterPassword->updateMasterPassword($request);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdateMasterPasswordThrowException(): void
    {
        $request = new UpdateMasterPassRequest(self::$faker->password(), self::$faker->password(), self::$faker->sha1());

        $this->account->expects(self::once())
                      ->method('getAccountsPassData')
                      ->willThrowException(new RuntimeException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while updating the accounts\' passwords');

        $this->accountMasterPassword->updateMasterPassword($request);
    }

    /**
     * @throws Exception
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPassword(): void
    {
        $request = new UpdateMasterPassRequest(
            self::$faker->password(),
            self::$faker->password(),
            self::$faker->sha1()
        );
        $accountData = array_map(static fn() => AccountDataGenerator::factory()->buildAccountHistoryData(), range(0, 9));

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willReturn($accountData);
        $this->accountHistory->expects(self::exactly(10))
                             ->method('updatePasswordMasterPass');
        $this->accountCrypt->expects(self::exactly(10))
                           ->method('getPasswordEncrypted')
                           ->willReturn(new EncryptedPassword('a_password', 'a_key'));

        $this->accountMasterPassword->updateHistoryMasterPassword($request);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPasswordWithNoAccounts(): void
    {
        $request =
            new UpdateMasterPassRequest(
                self::$faker->password(),
                self::$faker->password(),
                self::$faker->sha1()
            );

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willReturn([]);
        $this->accountHistory->expects(self::never())
                             ->method('updatePasswordMasterPass');
        $this->crypt->expects(self::never())
                    ->method('decrypt');
        $this->crypt->expects(self::never())
                    ->method('makeSecuredKey');
        $this->crypt->expects(self::never())
                    ->method('encrypt');

        $this->accountMasterPassword->updateHistoryMasterPassword($request);
    }

    /**
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPasswordThrowException(): void
    {
        $request = new UpdateMasterPassRequest(self::$faker->password(), self::$faker->password(), self::$faker->sha1());

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willThrowException(new RuntimeException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Error while updating the accounts\' passwords in history');

        $this->accountMasterPassword->updateHistoryMasterPassword($request);
    }

    /**
     * A partial re-key failure in history accounts must cause updateHistoryMasterPassword
     * to throw ServiceException so the transaction rolls back.
     *
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPasswordThrowsServiceExceptionOnPartialError(): void
    {
        $request = new UpdateMasterPassRequest(self::$faker->password(), self::$faker->password(), self::$faker->sha1());
        $accountData = array_map(static fn() => AccountDataGenerator::factory()->buildAccountHistoryData(), range(0, 9));

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willReturn($accountData);
        $this->crypt->expects(self::exactly(10))
                    ->method('decrypt')
                    ->willThrowException(new SPException('test'));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessageMatches('/could not be re-encrypted/');

        $this->accountMasterPassword->updateHistoryMasterPassword($request);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createMock(AccountService::class);
        $this->accountHistory = $this->createMock(AccountHistoryService::class);
        $this->crypt = $this->createMock(CryptInterface::class);
        $this->accountCrypt = $this->createMock(AccountCryptService::class);

        $this->accountMasterPassword =
            new AccountMasterPassword(
                $this->application,
                $this->account,
                $this->accountHistory,
                $this->crypt,
                $this->accountCrypt
            );
    }
}
