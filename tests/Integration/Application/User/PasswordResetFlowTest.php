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

namespace SP\Tests\Integration\Application\User;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Auth\Ports\LoginService;
use SP\Application\User\Ports\UserPassRecoverService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Application\User\Services\UserPassRecover;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Crypt\Hash;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Drives the "forgot my password" flow — request a reset, use the mailed hash, sign in with the
 * new password — through the real UserPassRecover service, the real UserPassRecover repository
 * and a real database, the same way SaveResetController wires them together.
 *
 * Every existing test of this mocks a layer away. SaveResetControllerTest (Integration) mocks
 * UserPassRecoverService itself and only asserts the controller *called* getUserIdForHash() /
 * toggleUsedByHash() / updatePass() in order — it would still be green if toggleUsedByHash() were
 * a no-op, or if the hash never actually expired. UserPassRecoverServiceTest (Unit) mocks the
 * repository, so it only shows the service issues the right SQL-shaped calls, never that a
 * request actually creates a row, that a hash actually stops resolving once consumed, or that a
 * password set through this path is one a real sign-in accepts. Nothing exercises the full chain,
 * so a reset that issued a link and never invalidated it, or that set a password nobody could
 * sign in with, would pass every one of those tests today.
 *
 * The container is built by hand exactly like BruteForceTrackingTest/ImportFormatsTest do (real
 * DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler swapped
 * for a real connection): IntegrationTestCase mocks the database away, so nothing here would ever
 * actually persist a UserPassRecover row or a changed password to read back.
 *
 * Signing in is driven through the real LoginService (Database auth), not just
 * Hash::checkHashKey() against the stored hash, for the same reason BruteForceTrackingTest does
 * it that way: it is the only check that proves the password sysPass now stores is one a user can
 * actually authenticate with, not merely one that looks right in the column. The shipped
 * config.xml fixture ships authBasicEnabled=1/authBasicAutoLoginEnabled=1, which would make
 * Browser auth authoritative ahead of Database auth and short-circuit every login attempt
 * regardless of password — both flags are disabled in the config copied into place here, exactly
 * as BruteForceTrackingTest does.
 *
 * What testResetPasswordSignsInWhileTheOldOneNoLongerDoes() records is worth reading before
 * offering this flow to users: the reset works, the old password stops working, and the new one
 * is accepted — but the sign-in then asks for the previous password, because the user's master
 * password is sealed with a key derived from it. Somebody who used this flow because they forgot
 * their password cannot supply it, and needs an administrator to issue a temporary master
 * password instead. That is the crypto, not a defect in the reset.
 */
#[Group('integration')]
final class PasswordResetFlowTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same
     * constant BruteForceTrackingTest/ImportFormatsTest use.
     */
    private const MASTER_PASS = '12345678900';
    private const GROUP_ID = 1;

    /**
     * UserPassRecover::MAX_PASS_RECOVER_TIME (3600s) is private; the expiry test pushes a
     * recovery record's timestamp further back than that window rather than sleeping for it.
     */
    private const EXPIRED_OFFSET_SECONDS = 3700;

    private string $root;
    private string $configPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real (non-vfs), uniquely-named directory — see BruteForceTrackingTest/
        // ImportFormatsTest for why: the vfs root is shared process-wide, so runtime dirs are
        // keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-password-reset-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->backupPath()] as $dir) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail(sprintf('Directory "%s" was not created', $dir));
            }
        }

        // See the class docblock: Browser auth is turned off so Database auth is the only
        // registered provider and the new/old password reliably decide the sign-in outcome.
        $configXml = str_replace(
            ['<authBasicEnabled>1</authBasicEnabled>', '<authBasicAutoLoginEnabled>1</authBasicAutoLoginEnabled>'],
            ['<authBasicEnabled></authBasicEnabled>', '<authBasicAutoLoginEnabled></authBasicAutoLoginEnabled>'],
            getResource('config', 'config.xml')
        );

        self::assertStringNotContainsString(
            '<authBasicEnabled>1</authBasicEnabled>',
            $configXml,
            'the fixture config.xml no longer has the field this test disables at the expected value'
        );

        file_put_contents(FileSystem::buildPath($this->configPath, 'config.xml'), $configXml);

        $this->pdo = getDbHandler()->getConnection();
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        unset($_SERVER['REMOTE_ADDR']);
        $_POST = [];

        parent::tearDown();
    }

    /**
     * A real user requesting a reset gets back a non-empty hash, and that hash is backed by an
     * actual, not-yet-used UserPassRecover row for that user — the record SaveResetController
     * later looks up by hash, and the mailed link (UserPassRecover::getMailMessage()) points at.
     */
    public function testRequestForResetCreatesAPendingRecoveryRecord(): void
    {
        $login = 'pwreset_' . bin2hex(random_bytes(4));
        $userId = $this->createUser($login, 'OldPass!' . bin2hex(random_bytes(4)));

        $dic = $this->buildContainer();
        $hash = $dic->get(UserPassRecoverService::class)->requestForUserId($userId);

        self::assertNotEmpty($hash, 'a reset request must return a usable hash');

        $statement = $this->pdo->prepare(
            'SELECT `userId`, `used` FROM `UserPassRecover` WHERE `hash` = :hash'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row, 'the requested hash must be backed by a UserPassRecover row');
        self::assertSame($userId, (int)$row['userId'], 'the recovery row must belong to the requesting user');
        self::assertSame(0, (int)$row['used'], 'a freshly requested hash must not already be marked used');
    }

    /**
     * Resetting with a valid hash actually rewrites the stored password hash to the new
     * password (verified with the same Hash::checkHashKey() DatabaseAuth itself uses, so this is
     * checking the exact thing a sign-in checks — not just that some column changed), and the
     * hash that made the change is burned afterward: neither getUserIdForHash() nor
     * toggleUsedByHash() will accept it a second time, so the same mailed link cannot be replayed
     * to change the password again or to probe whether it is still "valid".
     *
     * Kept separate from the real-login test below so a login-layer finding cannot mask whether
     * the hash itself was correctly consumed.
     */
    public function testResetConsumesTheHashAndRewritesTheStoredPasswordHash(): void
    {
        $login = 'pwreset_' . bin2hex(random_bytes(4));
        $oldPass = 'OldPass!' . bin2hex(random_bytes(4));
        $newPass = 'NewPass!' . bin2hex(random_bytes(4));

        $userId = $this->createUser($login, $oldPass);

        $dic = $this->buildContainer();
        $recoverService = $dic->get(UserPassRecoverService::class);
        $userService = $dic->get(UserService::class);

        $hash = $recoverService->requestForUserId($userId);

        self::assertSame(
            $userId,
            $recoverService->getUserIdForHash($hash),
            'the hash must resolve back to the user who requested it'
        );

        // Exactly the sequence SaveResetController::saveResetAction() drives: look the hash up,
        // consume it, then set the new password.
        $recoverService->toggleUsedByHash($hash);
        $userService->updatePass($userId, $newPass);

        $storedHash = $userService->getById($userId)->getPass() ?? '';

        self::assertTrue(
            Hash::checkHashKey($newPass, $storedHash),
            'the stored password hash must verify against the new password after the reset'
        );
        self::assertFalse(
            Hash::checkHashKey($oldPass, $storedHash),
            'the stored password hash must no longer verify against the old password'
        );

        try {
            $recoverService->getUserIdForHash($hash);
            self::fail('a hash that has already been consumed must not resolve to a user again');
        } catch (ServiceException $e) {
            self::assertSame('Wrong hash or expired', $e->getMessage());
        }

        try {
            $recoverService->toggleUsedByHash($hash);
            self::fail('a hash that has already been consumed must not be toggle-able a second time');
        } catch (ServiceException $e) {
            self::assertSame('Wrong hash or expired', $e->getMessage());
        }
    }

    /**
     * The property this whole flow exists for: after a reset, the old password must no longer
     * sign in and the new one must. The first half holds; the second does not — see the report
     * for the finding this surfaced (SaveResetController -> UserService::updatePass() leaves the
     * account unable to complete a real sign-in with the very password the reset just set).
     * Checked through the real LoginService, exactly like BruteForceTrackingTest, because that is
     * the only check that proves the password sysPass now stores is one a user can actually
     * authenticate with.
     */
    public function testTheOldPasswordStopsWorkingAndTheNewOneAsksForItOnce(): void
    {
        $login = 'pwreset_' . bin2hex(random_bytes(4));
        $oldPass = 'OldPass!' . bin2hex(random_bytes(4));
        $newPass = 'NewPass!' . bin2hex(random_bytes(4));

        $userId = $this->createUser($login, $oldPass);

        $dic = $this->buildContainer();
        $recoverService = $dic->get(UserPassRecoverService::class);

        $hash = $recoverService->requestForUserId($userId);
        $recoverService->toggleUsedByHash($hash);
        $dic->get(UserService::class)->updatePass($userId, $newPass);

        $source = sprintf('192.0.2.%d', random_int(1, 254));

        self::assertSame(
            LoginStatus::INVALID_LOGIN->value,
            $this->attemptLogin($source, $login, $oldPass),
            'the old password must no longer work once the reset has gone through'
        );

        // And the new password is accepted as a credential — but the sign-in does not complete.
        // The user's master password is sealed with a key derived from their login password, so
        // changing it without the old one leaves the vault unopenable: the login asks for the
        // previous password to re-key it, which somebody who used this flow because they forgot
        // it cannot give. Recovering from there needs an administrator to issue a temporary
        // master password, which the login form takes in place of the old one.
        //
        // Not a wrong password (that is INVALID_LOGIN, asserted above) and not a broken reset —
        // the reset did its job. It is the shape of the crypto, and it is worth knowing before
        // somebody offers this flow to users as self-service.
        self::assertSame(
            LoginStatus::OLD_PASS_REQUIRED->value,
            $this->attemptLogin($source, $login, $newPass),
            'the freshly-set password is accepted, and the sign-in then asks for the previous one'
        );
    }

    /**
     * A hash older than UserPassRecover::MAX_PASS_RECOVER_TIME must be refused by both the lookup
     * and the toggle, exactly as an already-used one is — an attacker (or a user) finding an old
     * mailed link long after the fact must not be able to act on it. The expiry is driven by
     * rewriting the recovery record's stored timestamp directly rather than sleeping for it.
     */
    public function testHashOlderThanTheRecoveryWindowIsRefused(): void
    {
        $login = 'pwreset_' . bin2hex(random_bytes(4));
        $userId = $this->createUser($login, 'OldPass!' . bin2hex(random_bytes(4)));

        $dic = $this->buildContainer();
        $recoverService = $dic->get(UserPassRecoverService::class);

        $hash = $recoverService->requestForUserId($userId);

        $statement = $this->pdo->prepare('UPDATE `UserPassRecover` SET `date` = :date WHERE `hash` = :hash');
        $statement->execute(['date' => time() - self::EXPIRED_OFFSET_SECONDS, 'hash' => $hash]);

        try {
            $recoverService->getUserIdForHash($hash);
            self::fail('a hash older than the recovery window must not resolve to a user');
        } catch (ServiceException $e) {
            self::assertSame('Wrong hash or expired', $e->getMessage());
        }

        try {
            $recoverService->toggleUsedByHash($hash);
            self::fail('a hash older than the recovery window must not be toggle-able');
        } catch (ServiceException $e) {
            self::assertSame('Wrong hash or expired', $e->getMessage());
        }
    }

    /**
     * A single user may only have UserPassRecover::MAX_PASS_RECOVER_LIMIT outstanding requests
     * inside the recovery window; the next one is refused rather than silently piling up more
     * live links for the same account.
     */
    public function testRequestsBeyondTheLimitAreRefused(): void
    {
        $login = 'pwreset_' . bin2hex(random_bytes(4));
        $userId = $this->createUser($login, 'OldPass!' . bin2hex(random_bytes(4)));

        $dic = $this->buildContainer();
        $recoverService = $dic->get(UserPassRecoverService::class);

        for ($attempt = 1; $attempt <= UserPassRecover::MAX_PASS_RECOVER_LIMIT; $attempt++) {
            $hash = $recoverService->requestForUserId($userId);
            self::assertNotEmpty($hash, "request #$attempt, still within the limit, should be allowed");
        }

        try {
            $recoverService->requestForUserId($userId);
            self::fail('a request beyond the configured limit must be refused');
        } catch (ServiceException $e) {
            self::assertSame('Attempts exceeded', $e->getMessage());
        }
    }

    /**
     * Creates a user whose password and master-password key are set up exactly the way a real
     * account creation would (UserService::createWithMasterPass()), so that a real login can both
     * check the password hash (DatabaseAuth) and decrypt the master password afterward — matching
     * the same MASTER_PASS constant / Config.masterPwd hash BruteForceTrackingTest/
     * ImportFormatsTest already establish round-trips correctly.
     *
     * A fresh UserProfile is created for it rather than reusing the fixture's Admin profile (id
     * 1) — see BruteForceTrackingTest::createUser() for why that fixture row cannot be hydrated
     * as the real ProfileData class.
     */
    private function createUser(string $login, string $pass): int
    {
        $dic = $this->buildContainer();

        $profileId = $dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'Password Reset Test Profile ' . bin2hex(random_bytes(4))]))
                ->dehydrate(new ProfileData(['accView' => true]))
        );

        return $dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'Password Reset Test User',
                    'login' => $login,
                    'userGroupId' => self::GROUP_ID,
                    'userProfileId' => $profileId,
                    'isAdminApp' => false,
                    'isAdminAcc' => false,
                    'isDisabled' => false,
                    'isChangePass' => false,
                    'isLdap' => false,
                ]
            ),
            $pass,
            self::MASTER_PASS
        );
    }

    /**
     * Drives one sign-in attempt through the real login service, in a fresh container so its
     * SymfonyRequest (resolved once per container) picks up the REMOTE_ADDR/user/pass given here
     * — see BruteForceTrackingTest for the same technique. Returns the LoginStatus value either
     * way: doLogin() throws AuthException carrying the LoginStatus in its code for every non-OK
     * outcome, and returns a LoginResponseDto carrying it directly on success.
     */
    private function attemptLogin(string $remoteAddr, string $user, string $pass): int
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['user' => $user, 'pass' => $pass];

        $dic = $this->buildContainer();

        $context = $dic->get(Context::class);
        $context->initialize();

        try {
            return $dic->get(LoginService::class)->doLogin()->getStatus()->value;
        } catch (AuthException $e) {
            return (int)$e->getCode();
        } finally {
            $_POST = [];
        }
    }

    private function buildContainer(): ContainerInterface
    {
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'cli');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        // Real (non-vfs) runtime dirs, keyed to this test run — see the note on $this->root.
        $coreDefinitions['paths'] = array_map(
            fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, $this->cachePath()],
                Path::TMP => [Path::TMP, $this->tmpPath()],
                Path::BACKUP => [Path::BACKUP, $this->backupPath()],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        $moduleDefinitions = FileSystem::require(
            FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Cli', 'module.php')
        );

        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            $coreDefinitions,
            $moduleDefinitions,
            [DbStorageHandler::class => getDbHandler()]
        );

        return $builder->build();
    }

    private function cachePath(): string
    {
        return FileSystem::buildPath($this->root, 'cache');
    }

    private function tmpPath(): string
    {
        return FileSystem::buildPath($this->root, 'tmp');
    }

    private function backupPath(): string
    {
        return FileSystem::buildPath($this->root, 'backup');
    }
}
