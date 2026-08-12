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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigManager;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Export\Dtos\BackupFile as BackupFileDto;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use SP\Domain\Export\Dtos\BackupFiles;
use SP\Domain\Export\Dtos\BackupType;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The configuration page tells an administrator when the installation was last backed up and last
 * exported.
 *
 * "There aren't any backups available" is the answer when the file is missing, and it is the answer
 * the page gives whenever anything goes wrong reading it — so a page that says it is the only thing
 * ever asserted cannot tell "there is no backup" from "the backup could not be read".
 */
#[Group('integration')]
class BackupPanelTest extends IntegrationTestCase
{
    /**
     * The hash is part of the file name, so a fresh one per test is what keeps these independent:
     * the backup written by one test is not a file the next one could stumble on. The test
     * filesystem is built once for the whole process and every test shares it.
     */
    private string $backupHash;
    private string $exportHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupHash = uniqid('backup', true);
        $this->exportHash = uniqid('export', true);
    }

    protected function getConfigData(): array
    {
        return array_merge(
            parent::getConfigData(),
            ['getBackupHash' => $this->backupHash, 'getExportHash' => $this->exportHash]
        );
    }

    /**
     * With nothing backed up, the page says so.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNothingYet')]
    public function anInstallationWithNoBackupSaysSo()
    {
        IntegrationTestCase::runApp($this->buildConfigPage());
    }

    /**
     * With both files in place it reports when they were made instead. Without this, the message
     * above would be satisfied by a page that could never find a backup at all.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerReportsTheTimes')]
    public function anInstallationWithABackupReportsWhenItWasMade()
    {
        $container = $this->buildConfigPage();

        $this->givenABackup($container);
        $this->givenAnExport($container);

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function buildConfigPage(): ContainerInterface
    {
        return $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'configManager/index'])
        );
    }

    /**
     * The backup is two files, and the page only reports a backup when both are there — an archive
     * of the application without the database it belongs to is not a backup.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function givenABackup(ContainerInterface $container): void
    {
        $backupFiles = $container->get(BackupFiles::class)->withHash($this->backupHash);

        touch((string)$backupFiles->getAppBackupFile());
        touch((string)$backupFiles->getDbBackupFile());
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function givenAnExport(ContainerInterface $container): void
    {
        $paths = $container->get(PathsContext::class);

        touch((string)new BackupFileDto(BackupType::export, $this->exportHash, $paths[Path::BACKUP], 'gz'));
    }

    private function outputCheckerNothingYet(string $output): void
    {
        self::assertStringContainsString('There aren\'t any backups available', $output);
    }

    /**
     * The page shows the file's own time, so it reports what is on disk rather than what the
     * configuration last recorded.
     */
    private function outputCheckerReportsTheTimes(string $output): void
    {
        self::assertStringNotContainsString('There aren\'t any backups available', $output);
        self::assertStringContainsString(date('Y'), $output, 'the backup is dated');
    }
}
