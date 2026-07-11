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
use SP\Infrastructure\Adapter\In\Cli\Commands\Crypt\UpdateMasterPasswordCommand;
use SP\Infrastructure\Adapter\In\Cli\Commands\InstallCommand;
use SP\Tests\Support\DatabaseUtil;
use SP\Tests\Integration\Infrastructure\Adapter\In\Cli\CliTestCase;

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
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function setUp(): void
    {
        $this->unsetEnvironmentVariables();

        parent::setUp();

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
}
