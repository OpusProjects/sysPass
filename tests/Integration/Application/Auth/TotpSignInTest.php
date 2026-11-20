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

namespace SP\Tests\Integration\Application\Auth;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Auth\Ports\LoginService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\Base32;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Auth\Services\Totp;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Two-factor sign-in, through the real login service, the real UserTotp service and repository,
 * and a real database.
 *
 * The container is built by hand as PasswordResetFlowTest builds it, and for the same reasons:
 * IntegrationTestCase mocks the database away, so a secret stored and then read back at sign-in
 * would be read from a mock; and Browser auth is switched off in the copied config.xml so the
 * password alone decides the first factor.
 *
 * Codes are made with Totp::codeAt() for an explicit step rather than for "now": enrolment spends
 * the step that confirmed it, so the first sign-in has to present the next one, and a test that
 * straddled a thirty-second boundary would otherwise fail one run in so many.
 */
#[Group('integration')]
final class TotpSignInTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the hash `syspass.sql` stores in Config.masterPwd.
     */
    private const MASTER_PASS = '12345678900';
    private const GROUP_ID = 1;
    private const REMOTE_ADDR = '192.0.2.81';

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
            'syspass-totp-sign-in-' . bin2hex(random_bytes(6))
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
     * Nothing changes for somebody who has not turned it on.
     */
    #[Test]
    public function aUserWithoutASecondFactorSignsInWithTheirPassword(): void
    {
        [$login, $pass] = $this->newUser();

        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass));
    }

    /**
     * An enrolment nobody confirmed asks for nothing: the user may never have scanned the code,
     * and enforcing it would lock them out with a secret they do not have.
     */
    #[Test]
    public function aPendingEnrolmentIsNotEnforced(): void
    {
        [$login, $pass, $userId] = $this->newUser();

        $this->totp()->startEnrolment($userId);

        self::assertFalse($this->totp()->isEnabled($userId));
        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass));
    }

    #[Test]
    public function enrolmentIsConfirmedOnlyWithACodeFromItsSecret(): void
    {
        [, , $userId] = $this->newUser();

        $secret = $this->totp()->startEnrolment($userId);
        $step = Totp::stepAt(time());

        self::assertFalse($this->totp()->confirmEnrolment($userId, $this->wrongCode($secret, $step)));
        self::assertFalse($this->totp()->isEnabled($userId));

        self::assertTrue($this->totp()->confirmEnrolment($userId, Totp::codeAt($secret, $step)));
        self::assertTrue($this->totp()->isEnabled($userId));

        // Confirmed once: a second confirmation has nothing pending to act on.
        self::assertFalse($this->totp()->confirmEnrolment($userId, Totp::codeAt($secret, $step + 1)));
    }

    /**
     * Turning it on twice would silently replace the secret in the user's app with one they do
     * not have; it has to be turned off first.
     */
    #[Test]
    public function aConfirmedSecretIsNotReplacedByStartingAgain(): void
    {
        [, , $userId] = $this->newUser();
        $this->enrol($userId);

        $this->expectException(ServiceException::class);

        $this->totp()->startEnrolment($userId);
    }

    #[Test]
    public function signInAsksForTheCodeAndAcceptsTheRightOne(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        [$secret, $step] = $this->enrol($userId);

        self::assertSame(LoginStatus::TOTP_REQUIRED->value, $this->attemptLogin($login, $pass));
        self::assertSame(
            LoginStatus::TOTP_REQUIRED->value,
            $this->attemptLogin($login, $pass, $this->wrongCode($secret, $step + 1))
        );
        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass, Totp::codeAt($secret, $step + 1)));
    }

    /**
     * The code is the second factor, not a replacement for the first.
     */
    #[Test]
    public function theRightCodeDoesNotStandInForTheWrongPassword(): void
    {
        [$login, , $userId] = $this->newUser();
        [$secret, $step] = $this->enrol($userId);

        self::assertSame(
            LoginStatus::INVALID_LOGIN->value,
            $this->attemptLogin($login, 'not-the-password', Totp::codeAt($secret, $step + 1))
        );
    }

    /**
     * A code seen over a shoulder, or captured from a request, cannot be used again — nor can
     * any code from before it, which would otherwise still be inside the drift window.
     */
    #[Test]
    public function aCodeIsAcceptedOnceAndNothingBeforeItAfterwards(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        [$secret, $step] = $this->enrol($userId);

        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass, Totp::codeAt($secret, $step + 1)));
        self::assertSame(
            LoginStatus::TOTP_REQUIRED->value,
            $this->attemptLogin($login, $pass, Totp::codeAt($secret, $step + 1))
        );
        self::assertSame(LoginStatus::TOTP_REQUIRED->value, $this->attemptLogin($login, $pass, Totp::codeAt($secret, $step)));
    }

    /**
     * Two sign-ins presenting the same code at once both pass the check; only one may spend it.
     * The decision and the write are one UPDATE, so the second finds nothing to change.
     */
    #[Test]
    public function aStepIsSpentOnceEvenWhenBothChecksPassed(): void
    {
        [, , $userId] = $this->newUser();
        [$secret, $step] = $this->enrol($userId);
        $code = Totp::codeAt($secret, $step + 1);

        self::assertSame($step + 1, $this->totp()->verify($userId, $code));
        self::assertSame($step + 1, $this->totp()->verify($userId, $code));

        self::assertTrue($this->totp()->consume($userId, $step + 1));
        self::assertFalse($this->totp()->consume($userId, $step + 1));
    }

    /**
     * A wrong code is a failed sign-in for the limiter, as a wrong password is: six digits are a
     * million guesses, and nothing else bounds them. Being asked for the code is not.
     */
    #[Test]
    public function aWrongCodeCountsAsAFailedAttemptAndBeingAskedDoesNot(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        [$secret, $step] = $this->enrol($userId);

        $before = $this->trackedFailures();

        $this->attemptLogin($login, $pass);
        self::assertSame($before, $this->trackedFailures(), 'being asked for the code was counted');

        $this->attemptLogin($login, $pass, $this->wrongCode($secret, $step + 1));
        self::assertSame($before + 1, $this->trackedFailures(), 'a wrong code was not counted');
    }

    /**
     * Neither the base32 secret nor its raw bytes appear in the row: a dump of the database alone
     * does not yield the second factor.
     */
    #[Test]
    public function theSecretIsNotStoredInTheClear(): void
    {
        [, , $userId] = $this->newUser();
        [$secret] = $this->enrol($userId);

        $stored = $this->pdo->query('SELECT secret FROM UserTotp WHERE userId = ' . $userId)->fetchColumn();

        self::assertIsString($stored);
        self::assertStringNotContainsString($secret, $stored);
        self::assertStringNotContainsString(Base32::decode($secret), $stored);
    }

    /**
     * Ten codes, each good for one sign-in in place of the app, typed however the user copied it.
     */
    #[Test]
    public function aRecoveryCodeSignsInOnceInPlaceOfTheApp(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        $this->enrol($userId);

        $codes = $this->totp()->generateRecoveryCodes($userId);

        self::assertCount(10, array_unique($codes));
        self::assertSame(10, $this->totp()->countRecoveryCodes($userId));

        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass, strtoupper($codes[0])));
        self::assertSame(9, $this->totp()->countRecoveryCodes($userId));
        self::assertSame(LoginStatus::TOTP_REQUIRED->value, $this->attemptLogin($login, $pass, $codes[0]));

        self::assertSame(
            LoginStatus::OK->value,
            $this->attemptLogin($login, $pass, str_replace('-', ' ', $codes[1]))
        );
    }

    /**
     * Issuing a new set retires the old one: that is what somebody who has lost the sheet needs.
     */
    #[Test]
    public function newRecoveryCodesRetireTheOldOnes(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        $this->enrol($userId);

        $old = $this->totp()->generateRecoveryCodes($userId);
        $new = $this->totp()->generateRecoveryCodes($userId);

        self::assertSame(LoginStatus::TOTP_REQUIRED->value, $this->attemptLogin($login, $pass, $old[0]));
        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass, $new[0]));
    }

    /**
     * Another user's recovery code is not one of yours.
     */
    #[Test]
    public function aRecoveryCodeBelongsToItsUser(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        [, , $otherId] = $this->newUser();
        $this->enrol($userId);
        $this->enrol($otherId);

        $theirs = $this->totp()->generateRecoveryCodes($otherId);
        $this->totp()->generateRecoveryCodes($userId);

        self::assertSame(LoginStatus::TOTP_REQUIRED->value, $this->attemptLogin($login, $pass, $theirs[0]));
    }

    #[Test]
    public function turningItOffRemovesTheRecoveryCodes(): void
    {
        [, , $userId] = $this->newUser();
        $this->enrol($userId);
        $this->totp()->generateRecoveryCodes($userId);

        $this->totp()->disable($userId);

        self::assertSame(
            0,
            (int)$this->pdo->query('SELECT COUNT(*) FROM UserTotpRecovery WHERE userId = ' . $userId)->fetchColumn()
        );
    }

    /**
     * Neither the code nor anything that reads as it is stored.
     */
    #[Test]
    public function recoveryCodesAreNotStoredInTheClear(): void
    {
        [, , $userId] = $this->newUser();
        $this->enrol($userId);
        $codes = $this->totp()->generateRecoveryCodes($userId);

        $stored = implode(
            "\n",
            $this->pdo->query('SELECT hash FROM UserTotpRecovery WHERE userId = ' . $userId)->fetchAll(PDO::FETCH_COLUMN)
        );

        foreach ($codes as $code) {
            self::assertStringNotContainsStringIgnoringCase(str_replace('-', '', $code), $stored);
        }
    }

    #[Test]
    public function turningItOffStopsTheQuestion(): void
    {
        [$login, $pass, $userId] = $this->newUser();
        $this->enrol($userId);

        $this->totp()->disable($userId);

        self::assertSame(LoginStatus::OK->value, $this->attemptLogin($login, $pass));
    }

    #[Test]
    public function deletingTheUserDeletesTheSecret(): void
    {
        [, , $userId] = $this->newUser();
        $this->enrol($userId);

        $this->pdo->exec('DELETE FROM User WHERE id = ' . $userId);

        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM UserTotp WHERE userId = ' . $userId)->fetchColumn());
    }

    /**
     * @return array{string, string, int}
     */
    private function newUser(): array
    {
        $login = 'totp_' . bin2hex(random_bytes(4));
        $pass = 'TotpPass!' . bin2hex(random_bytes(4));

        return [$login, $pass, $this->createUser($login, $pass)];
    }

    /**
     * Enrol and confirm with the current step, which enrolment then counts as spent.
     *
     * @return array{string, int} The secret and the step that confirmed it
     */
    private function enrol(int $userId): array
    {
        $secret = $this->totp()->startEnrolment($userId);
        $step = Totp::stepAt(time());

        self::assertTrue($this->totp()->confirmEnrolment($userId, Totp::codeAt($secret, $step)));

        return [$secret, $step];
    }

    /**
     * Six digits that are not the code for any step a check at this time would accept.
     */
    private function wrongCode(string $secret, int $step): string
    {
        $valid = array_map(static fn(int $s) => Totp::codeAt($secret, $s), range($step - 2, $step + 2));

        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while (in_array($code, $valid, true));

        return $code;
    }

    private function totp(): UserTotpService
    {
        return $this->buildContainer()->get(UserTotpService::class);
    }

    private function trackedFailures(): int
    {
        return (int)$this->pdo
            ->query("SELECT COUNT(*) FROM Track WHERE ipv4 = INET6_ATON('" . self::REMOTE_ADDR . "')")
            ->fetchColumn();
    }

    private function createUser(string $login, string $pass): int
    {
        $dic = $this->buildContainer();

        $profileId = $dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'Totp Test Profile ' . bin2hex(random_bytes(4))]))
                ->dehydrate(new ProfileData(['accView' => true]))
        );

        return $dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'Totp Test User',
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
     * SymfonyRequest (resolved once per container) picks up the fields given here — see
     * BruteForceTrackingTest for the same technique. Returns the LoginStatus value either
     * way: doLogin() throws AuthException carrying the LoginStatus in its code for every non-OK
     * outcome, and returns a LoginResponseDto carrying it directly on success.
     */
    private function attemptLogin(string $user, string $pass, ?string $totp = null): int
    {
        $_SERVER['REMOTE_ADDR'] = self::REMOTE_ADDR;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['user' => $user, 'pass' => $pass] + ($totp === null ? [] : ['totp' => $totp]);

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
