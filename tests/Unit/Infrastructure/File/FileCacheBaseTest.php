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

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Infrastructure\File\FileCacheBase;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class FileCacheBaseTest
 *
 * Uses real temporary files: the underlying FileHandler extends SplFileObject and opens
 * the file eagerly in its constructor, so this can't be exercised reliably against vfsStream.
 */
#[Group('unitary')]
class FileCacheBaseTest extends UnitaryTestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_filecachebase_', true);
        mkdir($this->dir);
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'cache.dat';
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
     * A path handed to the constructor is opened right away (FileHandler's 'c+b' mode creates
     * a missing file), so a freshly built cache object already reports exists() === true even
     * before anything is written to it.
     *
     * @throws FileException
     */
    public function testConstructingWithAPathOpensTheFileImmediately(): void
    {
        self::assertFileDoesNotExist($this->file);

        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->exists());
    }

    /**
     * factory() must hand back an instance of whichever subclass called it (late static
     * binding), not a bare FileCacheBase — otherwise a caller relying on the subclass's own
     * methods (e.g. FileCache::load()) would get an object that can't do the job.
     *
     * @throws FileException
     */
    public function testFactoryBuildsAnInstanceOfTheCallingSubclass(): void
    {
        $subject = FileCacheBaseTestSubject::factory($this->file);

        self::assertInstanceOf(FileCacheBaseTestSubject::class, $subject);
        self::assertSame($this->file, $subject->currentPathFile());
    }

    /**
     * A cache object built without a path and never given one later cannot locate any file —
     * this is the guard that turns that programming error into a clear exception instead of a
     * null-pointer failure deeper inside load()/save().
     */
    public function testCheckOrInitializePathThrowsWhenNoPathIsAvailableAnywhere(): void
    {
        $subject = new FileCacheBaseTestSubject();

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Path is needed');

        $subject->initializePath();
    }

    /**
     * When the constructor was given no path, the first path passed to load()/save() becomes
     * the file the cache actually uses.
     *
     * @throws FileException
     */
    public function testCheckOrInitializePathAdoptsTheGivenPathWhenNoneWasSet(): void
    {
        $subject = new FileCacheBaseTestSubject();

        $subject->initializePath($this->file);

        self::assertSame($this->file, $subject->currentPathFile());
        self::assertTrue($subject->exists());
    }

    /**
     * Once the constructor already pinned the cache to a file, a later path argument (e.g. a
     * caller passing its own default to save($data, $path)) must not silently redirect the
     * object to a different file mid-lifetime.
     *
     * @throws FileException
     */
    public function testCheckOrInitializePathKeepsTheConstructorPathOnceSet(): void
    {
        $subject = new FileCacheBaseTestSubject($this->file);

        $subject->initializePath($this->dir . DIRECTORY_SEPARATOR . 'other.dat');

        self::assertSame($this->file, $subject->currentPathFile());
    }

    /**
     * A zero-byte cache file means a previous write never completed; it must always be treated
     * as expired regardless of how recently it was touched, or a half-written cache would look
     * valid forever.
     *
     * @throws FileException
     */
    public function testIsExpiredWhenTheCacheFileIsEmpty(): void
    {
        touch($this->file);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->isExpired(86400));
    }

    /**
     * A cache written inside the requested time window is still usable.
     *
     * @throws FileException
     */
    public function testIsExpiredWithinTheTimeWindow(): void
    {
        file_put_contents($this->file, 'cached');
        touch($this->file, time() - 10);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertFalse($subject->isExpired(3600));
    }

    /**
     * A cache older than the requested window must be reported as expired, so a stale value is
     * never handed back as if it were current.
     *
     * @throws FileException
     */
    public function testIsExpiredPastTheTimeWindow(): void
    {
        file_put_contents($this->file, 'cached');
        touch($this->file, time() - 7200);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->isExpired(3600));
    }

    /**
     * There is no meaningful expiry answer for a cache file that has been removed from under
     * the object (e.g. cleared externally); this must fail loudly rather than report a value.
     */
    public function testIsExpiredThrowsWhenTheCacheFileIsMissing(): void
    {
        file_put_contents($this->file, 'cached');
        $subject = new FileCacheBaseTestSubject($this->file);
        unlink($this->file);

        $this->expectException(FileException::class);

        $subject->isExpired();
    }

    /**
     * Same zero-byte guard as isExpired(), for the reference-date variant used when comparing
     * against a specific moment (e.g. "has this cache changed since request X started") rather
     * than a rolling time window.
     *
     * @throws FileException
     */
    public function testIsExpiredDateWhenTheCacheFileIsEmpty(): void
    {
        touch($this->file);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->isExpiredDate(1));
    }

    /**
     * A reference date after the file's modification time means the cache predates that
     * moment, so it counts as expired relative to it.
     *
     * @throws FileException
     */
    public function testIsExpiredDateWhenTheReferenceDateIsAfterTheFile(): void
    {
        file_put_contents($this->file, 'cached');
        touch($this->file, 2000000000);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->isExpiredDate(2000000001));
    }

    /**
     * A reference date at or before the file's modification time means the cache is at least as
     * new as that moment, so it must not be reported as expired.
     *
     * @throws FileException
     */
    public function testIsExpiredDateWhenTheReferenceDateIsNotAfterTheFile(): void
    {
        file_put_contents($this->file, 'cached');
        touch($this->file, 2000000000);
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertFalse($subject->isExpiredDate(2000000000));
    }

    /**
     * createPath() must not error out just because the cache directory is already there — a
     * naive unconditional mkdir() would fail in that case even though there is nothing to fix.
     *
     * @throws FileException
     */
    public function testCreatePathIsANoOpWhenTheDirectoryAlreadyExists(): void
    {
        $subject = new FileCacheBaseTestSubject($this->file);

        $subject->createPath();

        self::assertDirectoryExists($this->dir);
    }

    /**
     * If the directory backing an already-open cache file disappears (e.g. an operator clearing
     * var/cache while the process still holds the handle), createPath() must recreate it so the
     * next save() succeeds instead of failing with "no such file or directory".
     *
     * @throws FileException
     */
    public function testCreatePathRecreatesADirectoryRemovedAfterOpening(): void
    {
        $nestedDir = $this->dir . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nestedDir);
        $cacheFile = $nestedDir . DIRECTORY_SEPARATOR . 'cache.dat';

        $subject = new FileCacheBaseTestSubject($cacheFile);

        unlink($cacheFile);
        rmdir($nestedDir);
        self::assertDirectoryDoesNotExist($nestedDir);

        $subject->createPath();

        self::assertDirectoryExists($nestedDir);
    }

    /**
     * delete() removes the backing file and stays fluent, the way callers chain it (e.g.
     * "clear the old cache and rebuild it" in one expression).
     *
     * @throws FileException
     */
    public function testDeleteRemovesTheFileAndReturnsItself(): void
    {
        file_put_contents($this->file, 'cached');
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertSame($subject, $subject->delete());
        self::assertFileDoesNotExist($this->file);
    }

    /**
     * exists() is what callers use to decide between reading a cache and building one from
     * scratch, so it must track the file's real presence on disk, including after it is removed.
     *
     * @throws FileException
     */
    public function testExistsReflectsWhetherTheFileIsStillThere(): void
    {
        $subject = new FileCacheBaseTestSubject($this->file);

        self::assertTrue($subject->exists());

        $subject->delete();

        self::assertFalse($subject->exists());
    }
}

/**
 * FileCacheBase is abstract because it only satisfies part of FileCacheService — the actual
 * (de)serialization format (load/save/loadWith) is left to a concrete cache flavour such as
 * FileCache. This fills those three in with no-ops and adds thin public wrappers around the
 * protected path handling, so FileCacheBase's own file-locating and expiry logic can be
 * exercised directly, without pulling FileCache's own (out of scope here) behaviour in.
 */
final class FileCacheBaseTestSubject extends FileCacheBase
{
    public function load(?string $path = null, string ...$allowed): mixed
    {
        return null;
    }

    public function loadWith(string $class, string ...$nested): object
    {
        throw new LogicException('not used by this test');
    }

    public function save(mixed $data, ?string $path = null): FileCacheService
    {
        return $this;
    }

    public function initializePath(?string $path = null): void
    {
        $this->checkOrInitializePath($path);
    }

    public function currentPathFile(): ?string
    {
        return $this->path?->getFile();
    }
}
