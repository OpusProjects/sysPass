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

namespace SP\Tests\Unit\Application\Account\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use SP\Domain\Account\Dtos\EncryptedPassword;
use SP\Domain\Account\Models\AccountHistory;
use SP\Application\Account\Ports\AccountCryptService;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Services\AccountMasterPassword;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Crypt\Dtos\UpdateMasterPassRequest;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\UnitaryTestCase;

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
     * Demo mode no longer half-performs the rotation here.
     *
     * This used to skip every account and report them all as done, so that a demo visitor driving
     * the flow could not re-key anybody's accounts. Only that half stood still, though: the custom
     * fields were re-encrypted to the new password and the stored hash was written, so the
     * application ended up believing a password that opened none of the accounts — every secret on
     * the instance unreadable. The refusal now lives in `MasterPass::changeMasterPassword()`, which
     * every door reaches, so nothing gets this far on a demo instance and this pass no longer has
     * a special case. Pinned here because the skip read as a safety measure and would be easy to
     * put back.
     *
     * @throws ServiceException
     */
    public function testUpdateMasterPasswordHasNoDemoModeSpecialCase(): void
    {
        $this->config->getConfigData()->setDemoEnabled(true);

        $request = new UpdateMasterPassRequest(
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
     * AccountHistory.mPassHash is NOT NULL, and it records which master password a row is
     * encrypted under — processAccounts() skips rows whose hash doesn't match the current one.
     * getPasswordEncrypted() builds an EncryptedPassword without a hash, so the re-encrypted row
     * has to be stamped with the new password's hash before it is written; passing the bare value
     * through aborted the whole rotation on the database's NOT NULL constraint.
     *
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPasswordStampsTheNewMasterPassHash(): void
    {
        $request = new UpdateMasterPassRequest(
            self::$faker->password(),
            self::$faker->password(),
            self::$faker->sha1()
        );

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willReturn([AccountDataGenerator::factory()->buildAccountHistoryData()]);

        $this->accountCrypt->expects(self::once())
                           ->method('getPasswordEncrypted')
                           ->willReturn(new EncryptedPassword('a_password', 'a_key'));

        $this->accountHistory->expects(self::once())
                             ->method('updatePasswordMasterPass')
                             ->with(
                                 self::anything(),
                                 self::callback(
                                     static fn(EncryptedPassword $encryptedPassword) => $encryptedPassword->getHash() === $request->getHash()
                                         && $encryptedPassword->getPass() === 'a_password'
                                         && $encryptedPassword->getKey() === 'a_key'
                                 )
                             );

        $this->accountMasterPassword->updateHistoryMasterPassword($request);
    }

    /**
     * AccountHistory.mPassHash records which master password a row is currently encrypted under.
     * A row whose hash doesn't match the one being rotated away from (e.g. left over from an
     * earlier, aborted rotation) is skipped rather than decrypted with the wrong key — decrypting
     * it under the current master pass would produce garbage, not the real password. Skipping it
     * is also not counted as an error, so a rotation full of such rows still completes rather than
     * aborting.
     *
     * @throws ServiceException
     */
    public function testUpdateHistoryMasterPasswordSkipsARowWhoseHashDoesNotMatch(): void
    {
        $request = new UpdateMasterPassRequest(
            self::$faker->password(),
            self::$faker->password(),
            'the-current-master-pass-hash'
        );

        $mismatched = AccountDataGenerator::factory()
                                           ->buildAccountHistoryData()
                                           ->mutate(['mPassHash' => 'a-different-master-pass-hash']);

        $this->accountHistory->expects(self::once())
                             ->method('getAccountsPassData')
                             ->willReturn([$mismatched]);
        $this->crypt->expects(self::never())
                    ->method('decrypt');
        $this->accountCrypt->expects(self::never())
                           ->method('getPasswordEncrypted');
        $this->accountHistory->expects(self::never())
                             ->method('updatePasswordMasterPass');

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

    /**
     * processAccounts() reports this estimate to the log every 100 accounts during a live
     * rotation, which can run across tens of thousands of accounts — it is how an operator judges
     * whether a rotation is going to take seconds or hours. With items already processed and a
     * non-zero total, the estimate has to come from the actual elapsed-time/throughput math, not
     * from the "nothing processed yet" fallback (which always reports zero).
     */
    public function testGetETAEstimatesFromElapsedTimeAndThroughput(): void
    {
        [$eta, $rate] = AccountMasterPassword::getETA(time(), 5, 10);

        self::assertIsInt($eta);
        self::assertGreaterThanOrEqual(0, $eta);
        // Only the "nothing processed yet" branch can ever report a zero rate; 5 of 10 items
        // processed must come out strictly positive, proving the elapsed-time branch ran.
        self::assertGreaterThan(0, $rate);
    }

    /**
     * With nothing processed yet (or nothing to process), there is no throughput to measure —
     * the estimate must be reported as zero rather than dividing by zero or extrapolating from
     * no data.
     */
    public function testGetETAWithNothingProcessedYetReturnsZero(): void
    {
        self::assertSame([0, 0], AccountMasterPassword::getETA(time(), 0, 10));
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
