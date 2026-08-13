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

namespace SP\Tests\Unit\Infrastructure\File;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SP\Domain\Core\Exceptions\CheckException;
use SP\Domain\Core\PhpExtensionCheckerService;
use SP\Domain\Export\Dtos\BackupHandlers;
use SP\Domain\File\Ports\ArchiveHandlerInterface;
use SP\Infrastructure\File\FileBackupHandlersFactory;
use SP\Infrastructure\File\FileHandler;
use SP\Tests\Support\Stubs\PhpExtensionCheckerStub;

/**
 * Class FileBackupHandlersFactoryTest
 *
 * FileBackupHandlersFactory is what wires up the three sinks a backup run writes through: the
 * raw DB dump and the two archives it and the application files get folded into. Getting any of
 * those three wrong (wrong permissions on the dump, two archives colliding on one file, or the
 * phar gate not actually gating) turns a backup into either a data leak or a corrupt/missing one.
 *
 * Uses real temporary directories: build() opens the DB dump through FileHandler (which extends
 * SplFileObject and opens its file eagerly in its constructor) and constructs PharData archives,
 * neither of which can be exercised reliably against vfsStream.
 */
#[Group('unitary')]
class FileBackupHandlersFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_backupfactory_', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * The DB dump is the one file in a backup that holds the fully decrypted account data plus
     * the master password hash before it gets folded into the archive, so build() must hand back
     * a handler for a file that is already restricted to the owner rather than sitting at the
     * process umask (typically world-readable) for however long the rest of the backup takes.
     *
     * @throws CheckException
     */
    public function testBuildOpensTheDatabaseDumpRestrictedToOwner(): void
    {
        $factory = new FileBackupHandlersFactory(new PhpExtensionCheckerStub());

        $handlers = $factory->build($this->dir, 'abc123');

        $dbFilePath = $this->dir . DIRECTORY_SEPARATOR . 'database.sql';

        self::assertInstanceOf(BackupHandlers::class, $handlers);
        self::assertInstanceOf(FileHandler::class, $handlers->dbFile);
        self::assertSame($dbFilePath, $handlers->dbFile->getFile());
        self::assertFileExists($dbFilePath);
        self::assertSame('0600', substr(sprintf('%o', fileperms($dbFilePath)), -4));
    }

    /**
     * build() must name the DB and application archives differently and independently of one
     * another — otherwise the second ArchiveHandler constructed would silently reuse (and later
     * overwrite) the first one's file instead of producing two distinct backup outputs.
     *
     * @throws CheckException
     */
    public function testBuildProducesTwoDistinctlyNamedArchives(): void
    {
        $factory = new FileBackupHandlersFactory(new PhpExtensionCheckerStub());

        $handlers = $factory->build($this->dir, 'abc123');

        self::assertNotSame($handlers->dbArchive, $handlers->appArchive);

        $dbArchivePath = self::archivePathOf($handlers->dbArchive);
        $appArchivePath = self::archivePathOf($handlers->appArchive);

        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'sysPass_db-abc123.sql.tar', $dbArchivePath);
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'sysPass_app-abc123.tar.tar', $appArchivePath);
        self::assertNotSame($dbArchivePath, $appArchivePath);
    }

    /**
     * Both archive handlers build() constructs go through the same phar-availability gate; when
     * phar is missing, the caller needs the original exception (naming the missing extension)
     * rather than a confusing low-level failure once the backup gets further along.
     */
    public function testBuildThrowsWhenThePharExtensionIsUnavailable(): void
    {
        $factory = new FileBackupHandlersFactory(new UnavailablePharExtensionChecker());

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage("Oops, it seems that some extensions are not available: 'phar'");

        $factory->build($this->dir, 'abc123');
    }

    private static function archivePathOf(ArchiveHandlerInterface $handler): string
    {
        return (new ReflectionProperty($handler, 'archive'))->getValue($handler)->getPath();
    }
}

/**
 * A PhpExtensionCheckerService double that reports the 'phar' extension as unavailable, the way
 * PhpExtensionCheckerStub reports every extension as available — needed to exercise build()'s
 * propagation of the phar gate without depending on the real environment actually lacking phar.
 */
final class UnavailablePharExtensionChecker implements PhpExtensionCheckerService
{
    public function checkIsAvailable(string $extension, bool $exception = false): bool
    {
        if ($exception) {
            throw new CheckException(
                sprintf("Oops, it seems that some extensions are not available: '%s'", $extension)
            );
        }

        return false;
    }

    public function checkMandatory(): void
    {
    }

    public function getMissing(): array
    {
        return ['phar' => false];
    }

    public function checkPhar(bool $exception = false): bool
    {
        return $this->checkIsAvailable('phar', $exception);
    }
}
