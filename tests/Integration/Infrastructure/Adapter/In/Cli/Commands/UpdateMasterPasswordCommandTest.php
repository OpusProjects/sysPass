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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Cli\Commands;

use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\Group;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Application\Crypt\Ports\MasterPassService;
use SP\Domain\Database\DatabaseException;
use SP\Domain\Database\DatabaseConnectionData;
use SP\Infrastructure\Adapter\In\Cli\Commands\Crypt\UpdateMasterPasswordCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;
use SP\Tests\Support\DatabaseUtil;
use SP\Tests\Integration\Infrastructure\Adapter\In\Cli\CliTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

use function SP\Tests\getDbHandler;

/**
 * End-to-end test of the CLI master password update against a real database.
 *
 * The state is bootstrapped by running the REAL CLI installer (instead of the
 * pre-rewrite SQL fixtures, whose schema no longer matches), so this also
 * exercises two commands sharing one container: the connection created for
 * the install must be refreshed with the runtime credentials for the update.
 */
#[Group('integration')]
class UpdateMasterPasswordCommandTest extends CliTestCase
{
    public const CURRENT_MASTERPASS = '12345678900';
    public const NEW_MASTERPASS = '00123456789';

    private const TEST_DB = 'syspass-test-ump';

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateAborted(): void
    {
        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                '--masterPassword' => self::NEW_MASTERPASS,
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password update aborted', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateIsSuccessful(): void
    {
        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                '--masterPassword' => self::NEW_MASTERPASS,
                '--update' => null,
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password updated', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateFromEnvironmentVarIsAbort(): void
    {
        $this->setEnvironmentVariables();

        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password update aborted', $output);
    }

    private function setEnvironmentVariables(): void
    {
        putenv(sprintf('%s=%s',
                UpdateMasterPasswordCommand::$envVarsMapping['currentMasterPassword'],
                self::CURRENT_MASTERPASS)
        );
        putenv(sprintf('%s=%s',
                UpdateMasterPasswordCommand::$envVarsMapping['masterPassword'],
                self::NEW_MASTERPASS)
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateFromEnvironmentVarBlankCurrentMasterPassword(): void
    {
        putenv(sprintf('%s=',
                UpdateMasterPasswordCommand::$envVarsMapping['masterPassword'])
        );

        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password cannot be blank', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateFromEnvironmentVarBlankMasterPassword(): void
    {
        putenv(sprintf('%s=',
                UpdateMasterPasswordCommand::$envVarsMapping['currentMasterPassword'])
        );

        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password cannot be blank', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateFromEnvironmentVarIsSuccessful(): void
    {
        putenv(sprintf('%s=true',
                UpdateMasterPasswordCommand::$envVarsMapping['update'])
        );

        $this->setEnvironmentVariables();

        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password updated', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testSameMasterPassword(): void
    {
        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                '--masterPassword' => self::CURRENT_MASTERPASS,
                '--update' => null,
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Passwords are the same', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testWrongMasterPassword(): void
    {
        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => uniqid('', true),
                '--masterPassword' => self::NEW_MASTERPASS,
                '--update' => null,
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('The current master password does not match', $output);
    }

    /**
     * The command refuses to start a second rotation while one is already running:
     * re-encrypting every account is not safe to interleave with itself. Acquire the
     * same lock LockableTrait keys by the command name from outside the command
     * (simulating a second, concurrent invocation) and confirm the command reports
     * the conflict instead of proceeding.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testUpdateFailsWhenAlreadyRunning(): void
    {
        $store = SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore();
        $lockName = self::$dic->get(UpdateMasterPasswordCommand::class)->getName();
        $lock = (new LockFactory($store))->createLock($lockName);

        $this->assertTrue($lock->acquire(false), 'Precondition failed: could not acquire the command lock');

        try {
            $commandTester = $this->executeCommandTest(
                UpdateMasterPasswordCommand::class,
                [
                    '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                    '--masterPassword' => self::NEW_MASTERPASS,
                    '--update' => null,
                ]
            );

            $output = $commandTester->getDisplay();
            $this->assertStringContainsString('The command is already running in another process', $output);

            // The lock check happens before anything else runs: the master password
            // must be untouched, not merely "no accounts were re-encrypted".
            $this->assertTrue(
                self::$dic->get(MasterPassService::class)->checkMasterPassword(self::CURRENT_MASTERPASS)
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * The current master password is only asked for interactively when neither
     * --currentMasterPassword nor its env var is given. A scripted run that omits it
     * entirely (no option, no env var, no TTY to answer the prompt) must refuse rather
     * than silently proceeding with an empty password.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testMissingCurrentMasterPasswordIsRejected(): void
    {
        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--masterPassword' => self::NEW_MASTERPASS,
                '--update' => null,
            ]
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password cannot be blank', $output);
    }

    /**
     * Typing the current master password and its confirmation differently must abort
     * the same way a wrong password does: proceeding on an unconfirmed guess is
     * exactly the mistake the second prompt exists to catch. CliTestCase's
     * executeCommandTest() always runs non-interactively (Symfony returns the empty
     * default for every question without comparing two typed answers), so this needs
     * its own interactive CommandTester.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testCurrentMasterPasswordConfirmationMismatch(): void
    {
        $commandTester = $this->executeInteractiveCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--masterPassword' => self::NEW_MASTERPASS,
                '--update' => null,
            ],
            [self::CURRENT_MASTERPASS, uniqid('', true)]
        );

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Passwords do not match', $output);
    }

    /**
     * Same as above, for the new master password: mistyping its confirmation must
     * abort rather than silently locking in whichever of the two answers was read
     * first.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testNewMasterPasswordConfirmationMismatch(): void
    {
        $commandTester = $this->executeInteractiveCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                '--update' => null,
            ],
            [self::NEW_MASTERPASS, uniqid('', true)]
        );

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Passwords do not match', $output);
    }

    /**
     * The whole rotation (live accounts, their history, custom fields, and finally
     * the stored master password hash) runs as one unit: AccountMasterPassword counts
     * per-account decryption failures and, if any account failed, throws so the
     * surrounding DB transaction rolls back and the config hash is never updated. If
     * it did not, an account that failed to re-encrypt would be stuck: still on the
     * old password while the hash used to validate "the current master password"
     * had already moved to the new one. Insert one account whose stored ciphertext
     * cannot be decrypted with the current master password and confirm the command
     * reports the abort — and, crucially, that the OLD master password still
     * validates afterward.
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testUpdateAbortsAndLeavesEverythingOnTheOldPasswordWhenAnAccountFailsToReencrypt(): void
    {
        $this->insertUndecryptableAccount();

        $commandTester = $this->executeCommandTest(
            UpdateMasterPasswordCommand::class,
            [
                '--currentMasterPassword' => self::CURRENT_MASTERPASS,
                '--masterPassword' => self::NEW_MASTERPASS,
                '--update' => null,
            ]
        );

        // SymfonyStyle word-wraps long block messages, so match on fragments rather
        // than the whole sentence (which would otherwise break across lines).
        $output = $commandTester->getDisplay();
        $this->assertStringNotContainsString('Master password updated', $output);
        $this->assertStringContainsString('could not be re-encrypted', $output);
        $this->assertStringContainsString('rotation aborted', $output);

        // The DB transaction covering every account, its history and the config hash
        // rolls back as a whole: the current master password must still validate.
        $this->assertTrue(
            self::$dic->get(MasterPassService::class)->checkMasterPassword(self::CURRENT_MASTERPASS)
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function setUp(): void
    {
        $this->unsetEnvironmentVariables();

        parent::setUp();

        // A run that was interrupted (or a tearDown that could not finish) leaves the test database
        // behind, and the install below then answers "The database already exists" — which fails
        // every test in this class, not only the one that was interrupted. Start from a clean slate
        // rather than assuming one.
        DatabaseUtil::dropDatabase(self::TEST_DB);

        // Bootstrap an installed sysPass with the current master password
        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            [
                'adminLogin' => 'Admin',
                'databaseHost' => getenv('DB_SERVER'),
                'databaseName' => self::TEST_DB,
                'databaseUser' => getenv('DB_USER'),
                '--databasePassword' => getenv('DB_PASS'),
                '--adminPassword' => 'admin123',
                '--masterPassword' => self::CURRENT_MASTERPASS,
                '--install' => null,
            ]
        );

        $this->assertStringContainsString('Installation finished', $commandTester->getDisplay());
    }

    protected function tearDown(): void
    {
        // putenv() state would leak into the next test class
        $this->unsetEnvironmentVariables();

        $dbUser = (string)self::$dic->get(ConfigFileService::class)->getConfigData()->getDbUser();

        DatabaseUtil::dropDatabase(self::TEST_DB);

        if ($dbUser !== '') {
            // The DB auth host depends on the environment (wildcard on Docker,
            // the client address elsewhere)
            DatabaseUtil::dropUser($dbUser, '%');
            DatabaseUtil::dropUser($dbUser, SELF_IP_ADDRESS);

            if (is_string(SELF_HOSTNAME) && strlen(SELF_HOSTNAME) < 60) {
                DatabaseUtil::dropUser($dbUser, SELF_HOSTNAME);
            }
        }

        parent::tearDown();
    }

    private function unsetEnvironmentVariables(): void
    {
        foreach (UpdateMasterPasswordCommand::$envVarsMapping as $envVar) {
            putenv($envVar);
        }
    }

    /**
     * Runs a command interactively, feeding canned answers to its hidden-question
     * prompts. CliTestCase::executeCommandTest() always runs with 'interactive' =>
     * false, which is the right default for scripted/env-var runs but cannot reach a
     * "the two typed answers differ" branch — a non-interactive question returns its
     * (empty) default immediately, so both answers always come out equal.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function executeInteractiveCommandTest(string $commandClass, array $inputData, array $answers): CommandTester
    {
        $commandTester = new CommandTester(self::$dic->get($commandClass));
        $commandTester->setInputs($answers);
        $commandTester->execute($inputData, ['interactive' => true]);

        return $commandTester;
    }

    /**
     * Inserts an account whose stored pass/key are not valid ciphertext for any
     * master password, so decrypting it during a rotation always fails. Building this
     * through the real account-creation stack would also need a category, a client
     * and a logged-in user/ACL context this bare CLI container never wires up; a
     * direct insert against the just-installed test database (using the same runtime
     * credentials ConfigFileService now holds) is the minimal way to get one bad row
     * next to the well-formed accounts everything else assumes exist.
     *
     * @throws DatabaseException
     */
    private function insertUndecryptableAccount(): void
    {
        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();
        $pdo = getDbHandler(DatabaseConnectionData::getFromConfig($configData))->getConnectionSimple();

        $userId = (int)$pdo->query('SELECT id FROM User ORDER BY id LIMIT 1')->fetchColumn();
        $userGroupId = (int)$pdo->query('SELECT id FROM UserGroup ORDER BY id LIMIT 1')->fetchColumn();

        $pdo->prepare('INSERT INTO Category (name, hash) VALUES (:name, :hash)')
            ->execute(['name' => 'Corrupt test category', 'hash' => sha1('corrupt-category')]);
        $categoryId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO Client (name, hash) VALUES (:name, :hash)')
            ->execute(['name' => 'Corrupt test client', 'hash' => sha1('corrupt-client')]);
        $clientId = (int)$pdo->lastInsertId();

        // Random bytes are never valid ciphertext for the encryption scheme used to
        // store account passwords, so decrypting this row always throws regardless of
        // which master password is tried against it.
        $statement = $pdo->prepare(
            'INSERT INTO Account
                (userGroupId, userId, userEditId, clientId, categoryId, name, login, pass, `key`, dateAdd)
             VALUES
                (:userGroupId, :userId, :userId, :clientId, :categoryId, :name, :login, :pass, :key, NOW())'
        );
        $statement->execute([
            'userGroupId' => $userGroupId,
            'userId' => $userId,
            'clientId' => $clientId,
            'categoryId' => $categoryId,
            'name' => 'Undecryptable account',
            'login' => 'corrupt',
            'pass' => random_bytes(64),
            'key' => random_bytes(64),
        ]);
    }
}
