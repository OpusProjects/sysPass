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
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Infrastructure\Adapter\In\Cli\Commands\BackupCommand;
use SP\Tests\Integration\Infrastructure\Adapter\In\Cli\CliTestCase;

/**
 * End-to-end test of the CLI backup against a real database.
 *
 * NOTE: the backup output location is wired at container-build time from
 * Path::BACKUP (the --path option currently only selects where OLD backups
 * are pruned), so the assertions check the container's backup path.
 */
#[Group('integration')]
class BackupCommandTest extends CliTestCase
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testBackupIsSuccessful(): void
    {
        $this->setupDatabase();

        $commandTester = $this->executeCommandTest(BackupCommand::class, []);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Application and database backup completed successfully', $output);

        $this->checkBackupFilesAreCreated();
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testBackupFromEnvironmentVarIsSuccessful(): void
    {
        $this->setupDatabase();

        putenv(sprintf('%s=%s', BackupCommand::$envVarsMapping['path'], $this->getBackupPath()));

        $commandTester = $this->executeCommandTest(
            BackupCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Application and database backup completed successfully', $output);

        $this->checkBackupFilesAreCreated();
    }

    /**
     * .env is loaded with Dotenv::createImmutable(), which populates $_ENV/$_SERVER
     * only, never getenv() (see CliTestCase::buildContainer(), which relies on the
     * same $_ENV-only mechanism for CONFIG_PATH). Confirm a dotenv-only variable is
     * picked up too, not just one exported into the real process environment via
     * putenv(), as covered by testBackupFromEnvironmentVarIsSuccessful() above.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testBackupFromDotEnvVariableIsSuccessful(): void
    {
        $this->setupDatabase();

        $_ENV[BackupCommand::$envVarsMapping['path']] = $this->getBackupPath();

        $commandTester = $this->executeCommandTest(
            BackupCommand::class,
            null,
            false
        );

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Application and database backup completed successfully', $output);

        $this->checkBackupFilesAreCreated();
    }

    /**
     * --path must control where the backup is written, not just where old
     * backups are pruned.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testBackupHonorsCustomPath(): void
    {
        $this->setupDatabase();

        $customPath = CLI_TEST_ROOT . DIRECTORY_SEPARATOR . 'custom-backup';
        mkdir($customPath);

        $commandTester = $this->executeCommandTest(BackupCommand::class, ['--path' => $customPath]);

        $this->assertStringContainsString(
            'Application and database backup completed successfully',
            $commandTester->getDisplay()
        );

        // Archives land in the requested path...
        $custom = glob($customPath . DIRECTORY_SEPARATOR . 'sysPass_*');
        $this->assertNotEmpty($custom, 'No backup archives in the custom --path');
        $joined = implode(' ', $custom);
        $this->assertStringContainsString('sysPass_db-', $joined);
        $this->assertStringContainsString('sysPass_app-', $joined);

        // ...and NOT in the default backup directory
        $default = glob($this->getBackupPath() . DIRECTORY_SEPARATOR . 'sysPass_*');
        $this->assertEmpty($default, 'Backup was written to the default dir instead of --path');
    }

    /**
     * Without a reachable database the dump must fail
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testBackupFailsWithoutDatabase(): void
    {
        $commandTester = $this->executeCommandTest(BackupCommand::class, []);

        // the output of the command in the console
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Error while doing the backup', $output);
    }

    private function getBackupPath(): string
    {
        return self::$dic->get(PathsContext::class)[Path::BACKUP];
    }

    private function checkBackupFilesAreCreated(): void
    {
        $archives = glob($this->getBackupPath() . DIRECTORY_SEPARATOR . 'sysPass_*');

        $this->assertNotEmpty($archives, 'No backup archives were created');

        $types = implode(' ', $archives);

        $this->assertStringContainsString('sysPass_db-', $types);
        $this->assertStringContainsString('sysPass_app-', $types);
    }

    protected function setUp(): void
    {
        $this->unsetEnvironmentVariables();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // putenv() state would leak into the next test class
        $this->unsetEnvironmentVariables();

        parent::tearDown();
    }

    private function unsetEnvironmentVariables(): void
    {
        foreach (BackupCommand::$envVarsMapping as $envVar) {
            putenv($envVar);
            unset($_ENV[$envVar], $_SERVER[$envVar]);
        }
    }
}
