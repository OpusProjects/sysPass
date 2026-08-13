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
use SP\Domain\Database\DatabaseException;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;
use SP\Tests\Support\DatabaseUtil;
use SP\Tests\Integration\Infrastructure\Adapter\In\Cli\CliTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end test of the CLI installer against a real database.
 */
#[Group('integration')]
class InstallCommandTest extends CliTestCase
{
    /**
     * @var string[]
     */
    protected static array $commandInputData = [
        'adminLogin' => 'Admin',
        'databaseHost' => 'localhost',
        'databaseName' => 'syspass-test-install',
        'databaseUser' => 'syspass_user',
        '--databasePassword' => 'test123',
        '--adminPassword' => 'admin123',
        '--masterPassword' => '12345678900',
        '--install' => null,
    ];

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallationIsAborted(): void
    {
        // Without --install the non-interactive confirm defaults to "no";
        // --forceInstall is only required when sysPass is already installed
        $inputData = self::$commandInputData;
        unset($inputData['--install']);

        $commandTester = $this->executeCommandTest(InstallCommand::class, $inputData);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation aborted', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testNoDatabaseConnection(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            ['--forceInstall' => null]
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Unable to connect to DB', $output);
    }

    /**
     * checkForceInstall() only consults configData->isInstalled(); every other test
     * in this class runs against a config that starts fresh (never installed), so
     * this refusal has never seen a config that says otherwise. Install for real
     * once, then try again without --forceInstall against the SAME container the
     * first install just wrote its config into.
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testInstallAlreadyInstalledIsRefused(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            [
                'databaseHost' => getenv('DB_SERVER'),
                'databaseUser' => getenv('DB_USER'),
                '--databasePassword' => getenv('DB_PASS'),
                '--forceInstall' => null,
            ]
        );

        $firstRun = $this->executeCommandTest(InstallCommand::class, $inputData);
        $this->assertStringContainsString('Installation finished', $firstRun->getDisplay());

        // CommandBase snapshots configData in its constructor, and
        // executeCommandTest() resolves the command through the container's cached
        // singleton — which would still hold the pre-install "not installed"
        // snapshot taken before the run above. A real second CLI invocation is
        // always a brand new process (and container); make() (bypassing the cache,
        // unlike get()) gets a fresh instance that picks up the just-written config,
        // simulating that.
        unset($inputData['--forceInstall']);
        $secondCommandTester = new CommandTester(self::$dic->make(InstallCommand::class));
        $secondCommandTester->execute($inputData, ['interactive' => false]);

        $this->assertStringContainsString(
            "sysPass is already installed. Use '--forceInstall' to install it again.",
            $secondCommandTester->getDisplay()
        );

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        // Cleanup database and the DB user created by the first (real) install
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser((string)$configData->getDbUser());
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testEmptyAdminPassword(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            ['--adminPassword' => '']
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Admin password cannot be blank', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testEmptyMasterPassword(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            ['--masterPassword' => '']
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Master password cannot be blank', $output);
    }

    /**
     * getAdminPassword()/getMasterPassword() only catch a BLANK password before the
     * installer ever runs; every other still-blank field (starting with the admin
     * login) is validated by a second, independent layer — Installer::checkData() —
     * which throws InvalidArgumentException, a different exception type with its own
     * catch block in the command. Omitting the optional adminLogin argument reaches
     * that layer without needing a real database (checkData() runs before any
     * connection is attempted).
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallMissingAdminLoginIsRejected(): void
    {
        $inputData = self::$commandInputData;
        unset($inputData['adminLogin']);

        $commandTester = $this->executeCommandTest(InstallCommand::class, $inputData);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Please, enter the admin username', $output);
    }

    /**
     * Typing the admin password and its confirmation differently must abort before
     * ever touching a database: installing with an admin password nobody actually
     * confirmed would lock the fresh install's only account out from the start.
     * CliTestCase::executeCommandTest() always runs non-interactively (a
     * non-interactive question returns its empty default immediately, so two
     * separate answers can never come out different), so this needs its own
     * interactive CommandTester.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallAdminPasswordConfirmationMismatch(): void
    {
        $inputData = self::$commandInputData;
        unset($inputData['--adminPassword']);

        $commandTester = $this->executeInteractiveCommandTest(
            InstallCommand::class,
            $inputData,
            ['admin123', uniqid('', true)]
        );

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Passwords do not match', $output);
    }

    /**
     * Same as above, for the master password: mistyping its confirmation must abort
     * rather than installing with whichever of the two typed answers happened to be
     * read first — the admin would then not actually know the master password
     * protecting the data they are about to store.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallMasterPasswordConfirmationMismatch(): void
    {
        $inputData = self::$commandInputData;
        unset($inputData['--masterPassword']);

        $commandTester = $this->executeInteractiveCommandTest(
            InstallCommand::class,
            $inputData,
            ['12345678900', uniqid('', true)]
        );

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Passwords do not match', $output);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testInstallIsSuccessful(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            [
                'databaseHost' => getenv('DB_SERVER'),
                'databaseUser' => getenv('DB_USER'),
                '--databasePassword' => getenv('DB_PASS'),
                '--forceInstall' => null
            ]
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation finished', $output);

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        // Cleanup database
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser((string)$configData->getDbUser());
    }

    /**
     * The DB auth host depends on the environment (wildcard on Docker, the
     * client address elsewhere): try every variant the installer may have used
     */
    private static function dropTestUser(string $user): void
    {
        if ($user === '') {
            return;
        }

        DatabaseUtil::dropUser($user, '%');
        DatabaseUtil::dropUser($user, SELF_IP_ADDRESS);

        if (is_string(SELF_HOSTNAME) && strlen(SELF_HOSTNAME) < 60) {
            DatabaseUtil::dropUser($user, SELF_HOSTNAME);
        }
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testInstallAndLanguageIsSet(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            [
                'databaseHost' => getenv('DB_SERVER'),
                'databaseUser' => getenv('DB_USER'),
                '--databasePassword' => getenv('DB_PASS'),
                '--language' => 'es_ES',
                '--forceInstall' => null
            ]
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation finished', $output);

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        $this->assertEquals($configData->getSiteLang(), $inputData['--language']);

        // Cleanup database
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser((string)$configData->getDbUser());
    }

    /**
     * An unknown language (e.g. the glibc LANGUAGE value "en_US:en" leaking in)
     * must not be persisted as a broken locale — it falls back to en_US.
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testInstallRejectsUnknownLanguage(): void
    {
        $inputData = array_merge(
            self::$commandInputData,
            [
                'databaseHost' => getenv('DB_SERVER'),
                'databaseUser' => getenv('DB_USER'),
                '--databasePassword' => getenv('DB_PASS'),
                '--language' => 'en_US:en',
                '--forceInstall' => null
            ]
        );

        $commandTester = $this->executeCommandTest(InstallCommand::class, $inputData);

        $this->assertStringContainsString('Installation finished', $commandTester->getDisplay());

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        $this->assertEquals('en_US', $configData->getSiteLang());

        // Cleanup database
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser((string)$configData->getDbUser());
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     */
    public function testInstallAndHostingModeIsUsed(): void
    {
        $databaseUser = 'syspass';
        $databasePassword = 'syspass123';

        DatabaseUtil::createDatabase(self::$commandInputData['databaseName']);
        DatabaseUtil::createUser(
            $databaseUser,
            $databasePassword,
            self::$commandInputData['databaseName'],
            getenv('DB_SERVER')
        );

        $inputData = array_merge(
            self::$commandInputData,
            [
                'databaseHost' => getenv('DB_SERVER'),
                'databaseUser' => $databaseUser,
                '--databasePassword' => $databasePassword,
                '--hostingMode' => null,
                '--forceInstall' => null
            ]
        );

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            $inputData
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation finished', $output);

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        $this->assertEquals($configData->getDbUser(), $databaseUser);
        $this->assertEquals($configData->getDbPass(), $databasePassword);

        // Cleanup database and the hosting user created above
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser($databaseUser);
        DatabaseUtil::dropUser($databaseUser, (string)getenv('DB_SERVER'));
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallFromEnvironmentVarIsAbort(): void
    {
        $this->setEnvironmentVariables();

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation aborted', $output);
    }

    private function setEnvironmentVariables(): void
    {
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['databaseHost'],
                getenv('DB_SERVER'))
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['databaseUser'],
                getenv('DB_USER'))
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['databasePassword'],
                getenv('DB_PASS'))
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['databaseName'],
                self::$commandInputData['databaseName'])
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['adminLogin'],
                self::$commandInputData['adminLogin'])
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['adminPassword'],
                self::$commandInputData['--adminPassword'])
        );
        putenv(sprintf('%s=%s',
                InstallCommand::$envVarsMapping['masterPassword'],
                self::$commandInputData['--masterPassword'])
        );
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallFromEnvironmentVarIsAbortedWithForce(): void
    {
        putenv(sprintf('%s=true',
                InstallCommand::$envVarsMapping['forceInstall'])
        );

        $this->setEnvironmentVariables();

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation aborted', $output);
    }

    /**
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testInstallFromEnvironmentVarIsSuccessful(): void
    {
        putenv(sprintf('%s=true',
                InstallCommand::$envVarsMapping['forceInstall'])
        );
        putenv(sprintf('%s=true',
                InstallCommand::$envVarsMapping['install'])
        );

        $this->setEnvironmentVariables();

        $commandTester = $this->executeCommandTest(
            InstallCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Installation finished', $output);

        $configData = self::$dic->get(ConfigFileService::class)->getConfigData();

        // Cleanup database
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);
        self::dropTestUser((string)$configData->getDbUser());
    }

    protected function setUp(): void
    {
        $this->unsetEnvironmentVariables();

        parent::setUp();
    }

    /**
     * @throws DatabaseException
     */
    protected function tearDown(): void
    {
        // putenv() state would leak into the next test class
        $this->unsetEnvironmentVariables();

        // A failed test may leave the database behind
        DatabaseUtil::dropDatabase(self::$commandInputData['databaseName']);

        parent::tearDown();
    }

    private function unsetEnvironmentVariables(): void
    {
        foreach (InstallCommand::$envVarsMapping as $envVar) {
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
}
