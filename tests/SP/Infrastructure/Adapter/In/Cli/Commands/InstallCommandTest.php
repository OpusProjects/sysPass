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

namespace SP\Tests\Infrastructure\Adapter\In\Cli\Commands;

use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\Group;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Infrastructure\Database\DatabaseException;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;
use SP\Tests\DatabaseUtil;
use SP\Tests\Infrastructure\Adapter\In\Cli\CliTestCase;

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
}
