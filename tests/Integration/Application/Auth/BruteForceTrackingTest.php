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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Auth\Ports\LoginService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
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
 * Drives repeated failed sign-ins through the real login service (SP\Application\Auth\Services\Login,
 * via LoginBase::checkTracking()/addTracking()) against the real Track repository and a real
 * database, to prove that the tracker actually blocks an attacker rather than merely being asked
 * to and trusted on its word.
 *
 * Every existing test of this (tests/Unit/Application/Auth/Services/Login*Test.php) mocks
 * TrackService itself, so all it can show is "when the mock says blocked, checkTracking() throws" —
 * nothing exercises that a failed attempt is actually recorded, counted against the right key, or
 * that the real threshold in SP\Application\Security\Services\Track (10 attempts inside a 600
 * second window, keyed on REMOTE_ADDR + the calling class name as "source") ever fires. A tracker
 * that silently dropped every insert, or counted attempts against the wrong column, would pass
 * every one of those mocked tests while leaving password guessing unlimited.
 *
 * The container is built by hand exactly like ImportFormatsTest/ExportImportRoundTripTest do (real
 * DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler swapped for
 * a real connection) for the same reason: IntegrationTestCase mocks the database away, so nothing
 * to do with Track would ever actually persist. Unlike those two, this one also exercises
 * RequestService/SymfonyRequest for real (Login::doLogin() reads 'user'/'pass' from the request and
 * TrackService keys tracking on $_SERVER['REMOTE_ADDR']) — SymfonyRequest is resolved once per
 * container (php-di's default scope is singleton), so a fresh container is built for every login
 * attempt that needs different superglobals, mirroring how a real deployment gets a fresh container
 * per HTTP request.
 *
 * The shipped config.xml fixture (tests/res/config/config.xml) ships authBasicEnabled=1 and
 * authBasicAutoLoginEnabled=1. With those on, AuthProviderService registers Browser auth as an
 * *authoritative* provider ahead of Database auth (see CoreDefinitions), and BrowserAuth considers
 * itself authoritative purely from that config flag — regardless of whether PHP_AUTH_USER/PHP_AUTH_PW
 * are actually present. A scripted request never sets those, so Browser auth would fail
 * authoritatively and short-circuit every attempt (right password or wrong) before Database auth is
 * ever reached, making it impossible to tell a genuine credential failure from a tracking refusal.
 * Both basic-auth flags are turned off in the config copied into place here, leaving Database auth
 * as the sole registered provider — the same effect LDAP already has by shipping disabled.
 */
#[Group('integration')]
final class BruteForceTrackingTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same
     * constant ImportFormatsTest/ExportImportRoundTripTest use.
     */
    private const MASTER_PASS = '12345678900';
    private const GROUP_ID = 1;

    private string $root;
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real (non-vfs), uniquely-named directory: CryptPKI writes a real RSA keypair under
        // Path::CONFIG (needed because Login::doLogin() decrypts the 'pass' param through a real
        // RequestService), and the vfs root is shared process-wide, so this is keyed to this test
        // run alone — see ImportFormatsTest for the same reasoning.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-brute-force-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->backupPath()] as $dir) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail(sprintf('Directory "%s" was not created', $dir));
            }
        }

        // See the class docblock: Browser auth is turned off so Database auth is the only
        // registered provider and a wrong password reliably surfaces as a Database auth failure.
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
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        unset($_SERVER['REMOTE_ADDR']);
        $_POST = [];

        parent::tearDown();
    }

    /**
     * The core property: once a source has failed to sign in TIME_TRACKING_MAX_ATTEMPTS (10) times
     * inside the tracking window, its very next attempt is refused for tracking — and that holds
     * even when, this time, the credentials it presents are correct. If it were refused with the
     * ordinary "wrong credentials" status instead, this attempt tells you nothing: a login that
     * always fails would "pass" a test that only checks for failure. Using the right password on the
     * blocked attempt is what proves the refusal came from the tracker, not from the credentials.
     *
     * The same run also establishes the per-source property: a second address, which has made only
     * one failed attempt of its own, is still refused with the ordinary credentials status — the
     * first address running out its budget does not touch the second address's count.
     *
     * The attempts are wrong passwords against a real account, which is the attack this exists to
     * stop. They used not to count: every part of the sign-in flow recorded into a bucket named
     * after its own class, and the only check that can refuse read one of them, so a wrong
     * password went somewhere nothing looked. Guessing was unlimited and only repeated empty
     * submissions ever tripped the gate.
     */
    public function testRepeatedFailuresBlockTheSourceNotACredentialCheck(): void
    {
        $login = 'bftest_' . bin2hex(random_bytes(4));
        $correctPass = 'BfCorrect!' . bin2hex(random_bytes(4));

        $this->createUser($login, $correctPass);

        // Two disjoint TEST-NET ranges (RFC 5737) so the addresses can never collide with each
        // other regardless of the random octet, plus the octet itself so two runs of this test
        // (e.g. against a database another process is also using) do not share a source either.
        $sourceA = sprintf('203.0.113.%d', random_int(1, 254));
        $sourceB = sprintf('198.51.100.%d', random_int(1, 254));

        $wrongPass = 'BfWrong!' . bin2hex(random_bytes(4));

        // Ten wrong passwords against a real account from A — the actual attack. Each fails on the
        // credentials, not on tracking, until the tenth brings A's count up to the threshold.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            self::assertSame(
                LoginStatus::INVALID_LOGIN->value,
                $this->attemptLogin($sourceA, $login, $wrongPass),
                "attempt $attempt from the tracked source should fail on the credentials, not tracking"
            );
        }

        // One wrong password from a different address: an ordinary failure, same as A's first
        // nine. Proves A's ten recorded failures did not leak into B's count.
        self::assertSame(
            LoginStatus::INVALID_LOGIN->value,
            $this->attemptLogin($sourceB, $login, $wrongPass),
            'a different source must not be affected by another source running out its attempts'
        );

        // The 11th attempt from A, this time with real, correct credentials: if tracking were not
        // wired up (or counted against the wrong key), this would succeed. It is refused instead,
        // and specifically for tracking, not credentials.
        self::assertSame(
            LoginStatus::MAX_ATTEMPTS_EXCEEDED->value,
            $this->attemptLogin($sourceA, $login, $correctPass),
            'the 11th attempt from the tracked source must be refused for tracking even though '
            . 'the credentials are correct'
        );
    }

    /**
     * A successful sign-in has to actually be reachable before the threshold trips — otherwise the
     * blocking case above would pass for the wrong reason (a login that never succeeds "blocks"
     * every attempt trivially). A couple of ordinary failures are logged first to show the
     * remaining budget does not stop the correct attempt from working.
     */
    public function testASuccessfulSignInIsPossibleBeforeTheThresholdIsReached(): void
    {
        $login = 'bftest_' . bin2hex(random_bytes(4));
        $correctPass = 'BfCorrect!' . bin2hex(random_bytes(4));
        $wrongPass = 'BfWrong!' . bin2hex(random_bytes(4));

        $this->createUser($login, $correctPass);

        $source = sprintf('192.0.2.%d', random_int(1, 254));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertSame(
                LoginStatus::INVALID_LOGIN->value,
                $this->attemptLogin($source, $login, $wrongPass),
                "attempt $attempt with the wrong password should fail on credentials"
            );
        }

        self::assertSame(
            LoginStatus::OK->value,
            $this->attemptLogin($source, $login, $correctPass),
            'a correct sign-in, still under the tracking threshold, must succeed'
        );
    }

    /**
     * Creates a user whose password and master-password key are set up exactly the way a real
     * account creation would (UserService::createWithMasterPass()), so that a real login can both
     * check the password hash (DatabaseAuth) and decrypt the master password afterward
     * (LoginMasterPass::loadCurrent()) — matching the same MASTER_PASS constant / Config.masterPwd
     * hash ImportFormatsTest/ExportImportRoundTripTest already establish round-trips correctly.
     *
     * A fresh UserProfile is created for it rather than reusing the fixture's Admin profile (id 1):
     * that row's `profile` blob is a legacy PHP-serialized SP\DataModel\ProfileData object (a
     * straight dump from the pre-rewrite sysPass version the fixture was captured from), and
     * SerializedModel::hydrate() — which a successful Login::doLogin() calls on it via
     * UserProfileService — only accepts that legacy format when the caller asks to deserialize it
     * AS __PHP_Incomplete_Class (see SerdeTest::testDeserializeWithClass() /
     * testDeserializeWithClassException()); asked for the real ProfileData class, as production
     * code does, it throws "Invalid target class" instead.
     */
    private function createUser(string $login, string $pass): void
    {
        $dic = $this->buildContainer();

        $profileId = $dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'Brute Force Test Profile ' . bin2hex(random_bytes(4))]))
                ->dehydrate(new ProfileData(['accView' => true]))
        );

        $dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'Brute Force Test User',
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
     * SymfonyRequest (resolved once per container) picks up the REMOTE_ADDR/user/pass given here —
     * see the class docblock. Returns the LoginStatus value either way: doLogin() throws
     * AuthException carrying the LoginStatus in its code for every non-OK outcome (wrong
     * credentials, blocked by tracking, ...), and returns a LoginResponseDto carrying it directly
     * on success.
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
