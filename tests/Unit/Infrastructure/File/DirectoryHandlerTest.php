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

use Directory;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Exceptions\CheckException;
use SP\Infrastructure\File\DirectoryHandler;

/**
 * Class DirectoryHandlerTest
 *
 * checkOrCreate() is the gate the installer and the backup service both call before writing
 * into a runtime directory ('config', 'var/backup', ...): it decides whether that directory is
 * usable at all, self-healing a missing one and refusing to continue on one it cannot write to.
 *
 * Unlike FileHandler, DirectoryHandler never opens anything itself — every method is a thin
 * wrapper around is_dir()/mkdir()/is_writable()/dir() — so it can be exercised against vfsStream
 * (already the test suite's virtual filesystem, wired up in tests/bootstrap.php). That is what
 * lets the "not writable" and "cannot create" branches be driven deterministically, rather than
 * relying on real OS permissions the test container's root user would bypass entirely.
 */
#[Group('unitary')]
class DirectoryHandlerTest extends TestCase
{
    public function testGetPathReturnsTheConfiguredPath(): void
    {
        $handler = new DirectoryHandler('/some/configured/path');

        self::assertSame('/some/configured/path', $handler->getPath());
    }

    public function testIsDirIsTrueForAnExistingDirectory(): void
    {
        $dir = vfsStream::newDirectory('directoryhandler-is-dir-existing')->at(vfsStreamWrapper::getRoot());

        self::assertTrue((new DirectoryHandler($dir->url()))->isDir());
    }

    public function testIsDirIsFalseForAPathThatIsNotThere(): void
    {
        $missing = vfsStreamWrapper::getRoot()->url() . '/directoryhandler-is-dir-missing';

        self::assertFalse((new DirectoryHandler($missing))->isDir());
    }

    /**
     * create() is regularly handed a whole missing chain in one call (e.g. a fresh var/backup
     * path on an install that has never run a backup before), so it has to build every
     * intermediate segment, not just the leaf directory.
     */
    public function testCreateBuildsTheWholeMissingPathRecursively(): void
    {
        $root = vfsStream::newDirectory('directoryhandler-recursive')->at(vfsStreamWrapper::getRoot());
        $nested = $root->url() . '/a/b/c';
        $handler = new DirectoryHandler($nested);

        self::assertFalse($handler->isDir());
        self::assertTrue($handler->create());
        self::assertTrue($handler->isDir());
    }

    /**
     * create() is also the first thing checkOrCreate() tries before giving up, so an
     * already-existing directory (mkdir() itself would fail on it) has to count as success
     * rather than a spurious failure for a directory that needed no work at all.
     */
    public function testCreateReturnsTrueWhenTheDirectoryAlreadyExists(): void
    {
        $dir = vfsStream::newDirectory('directoryhandler-create-existing')->at(vfsStreamWrapper::getRoot());

        self::assertTrue((new DirectoryHandler($dir->url()))->create());
    }

    /**
     * A parent directory the process cannot write into (e.g. a misconfigured install) must make
     * create() report failure rather than let the caller believe the directory is now usable.
     */
    public function testCreateReturnsFalseWhenTheParentIsNotWritable(): void
    {
        $parent = vfsStream::newDirectory('directoryhandler-create-readonly-parent', 0555)
            ->at(vfsStreamWrapper::getRoot());
        $handler = new DirectoryHandler($parent->url() . '/child');

        self::assertFalse($handler->create());
    }

    public function testIsWritableReflectsThePermissions(): void
    {
        $writable = vfsStream::newDirectory('directoryhandler-writable', 0750)->at(vfsStreamWrapper::getRoot());
        $readOnly = vfsStream::newDirectory('directoryhandler-readonly', 0555)->at(vfsStreamWrapper::getRoot());

        self::assertTrue((new DirectoryHandler($writable->url()))->isWritable());
        self::assertFalse((new DirectoryHandler($readOnly->url()))->isWritable());
    }

    /**
     * The success path: checkOrCreate() can build the directory from nothing, and once built it
     * is also writable, so it must return silently instead of raising a false alarm.
     *
     * @throws CheckException
     */
    public function testCheckOrCreateSucceedsWhenTheDirectoryCanBeCreated(): void
    {
        $root = vfsStream::newDirectory('directoryhandler-checkorcreate-ok')->at(vfsStreamWrapper::getRoot());
        $handler = new DirectoryHandler($root->url() . '/fresh');

        $handler->checkOrCreate();

        self::assertTrue($handler->isDir());
        self::assertTrue($handler->isWritable());
    }

    /**
     * When the directory cannot be created at all (its parent is read-only here), the operator
     * needs the failing path named in the error rather than a generic message.
     */
    public function testCheckOrCreateThrowsWithThePathWhenCreationFails(): void
    {
        $parent = vfsStream::newDirectory('directoryhandler-checkorcreate-fails', 0555)
            ->at(vfsStreamWrapper::getRoot());
        $path = $parent->url() . '/child';
        $handler = new DirectoryHandler($path);

        try {
            $handler->checkOrCreate();
            self::fail('Expected a CheckException');
        } catch (CheckException $e) {
            self::assertSame(sprintf('Unable to create directory ("%s")', $path), $e->getMessage());
        }
    }

    /**
     * A directory that already exists but lost its write bit (e.g. an operator tightening
     * permissions after the fact) must still be flagged: checkOrCreate() only skips the create()
     * step for an existing directory, it must not skip the writability check that follows it.
     *
     * @throws CheckException
     */
    public function testCheckOrCreateThrowsWhenAnExistingDirectoryIsNotWritable(): void
    {
        $dir = vfsStream::newDirectory('directoryhandler-existing-readonly', 0555)
            ->at(vfsStreamWrapper::getRoot());
        $handler = new DirectoryHandler($dir->url());

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('Please, check the directory permissions');

        $handler->checkOrCreate();
    }

    /**
     * @throws CheckException
     */
    public function testGetDirReturnsARealDirectoryHandleThatListsItsEntries(): void
    {
        $dir = vfsStream::newDirectory('directoryhandler-listing')->at(vfsStreamWrapper::getRoot());
        vfsStream::newFile('entry.txt')->at($dir);

        $handle = (new DirectoryHandler($dir->url()))->getDir();

        self::assertInstanceOf(Directory::class, $handle);

        $entries = [];
        while (($entry = $handle->read()) !== false) {
            $entries[] = $entry;
        }

        self::assertContains('entry.txt', $entries);
    }

    /**
     * A path that isn't a directory at all (removed, never created, or a typo in config) must
     * surface as the application's own exception rather than a bare PHP warning the caller has
     * no defined way to catch.
     */
    public function testGetDirThrowsWhenThePathDoesNotExist(): void
    {
        $missing = vfsStreamWrapper::getRoot()->url() . '/directoryhandler-getdir-missing';
        $handler = new DirectoryHandler($missing);

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage(sprintf('Unable to open directory ("%s")', $missing));

        // dir() emits a PHP warning for a path that does not exist; @ mirrors how the production
        // call site itself does not (and cannot) suppress it, since the exception below is what
        // the caller is meant to rely on instead.
        @$handler->getDir();
    }
}
