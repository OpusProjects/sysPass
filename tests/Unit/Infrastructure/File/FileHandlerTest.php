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
use SP\Domain\Core\Exceptions\FileException;
use SP\Infrastructure\File\FileHandler;

/**
 * Class FileHandlerTest
 *
 * Uses real temporary files: FileHandler extends SplFileObject, which opens the
 * file in its constructor, so it can't be exercised against vfsStream reliably.
 */
#[Group('unitary')]
class FileHandlerTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_filehandler_', true);
        mkdir($this->dir);
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'test.txt';
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
     * @throws FileException
     */
    public function testWritePersistsToDisk(): void
    {
        $handler = new FileHandler($this->file, 'w');
        $result = $handler->write('hello world');

        self::assertInstanceOf(FileHandler::class, $result);

        unset($handler); // closing the handle flushes the buffer

        self::assertSame('hello world', file_get_contents($this->file));
    }

    /**
     * @throws FileException
     */
    public function testSavePersistsToDisk(): void
    {
        $handler = new FileHandler($this->file, 'c');
        $handler->save('saved content');

        unset($handler);

        self::assertSame('saved content', file_get_contents($this->file));
    }

    /**
     * The file is replaced, not truncated and rewritten.
     *
     * `config.xml` goes through `save()`, and it holds the database credentials, the password salt
     * and the master-password hash. `ftruncate(0)` followed by `fwrite()` has a window in which
     * that file is empty on disk, and a process killed in it — an OOM, a container stopped
     * mid-save, the host losing power — leaves an installation that cannot boot and cannot be
     * recovered through the UI. Nothing takes a backup first.
     *
     * The inode is the observable part of the fix: a rename gives the path a different file, where
     * a truncate keeps the same one. It is also exactly the property that makes the replacement
     * atomic for a reader, which is what this is for.
     *
     * @throws FileException
     */
    public function testSaveReplacesTheFileRatherThanTruncatingIt(): void
    {
        file_put_contents($this->file, 'the old contents');
        $before = fileinode($this->file);

        (new FileHandler($this->file, 'c+'))->save('the new contents');

        clearstatcache(true, $this->file);

        self::assertSame('the new contents', file_get_contents($this->file));
        self::assertNotSame($before, fileinode($this->file), 'the file must be replaced, not truncated');
    }

    /**
     * A save that cannot be completed leaves the file exactly as it was.
     *
     * This is the failure the change is about, and the only way to reach it deterministically here
     * is to make the temporary file impossible to create — a directory in its place does that for
     * root as well, which is what the suite runs as. Before the change the write went straight into
     * the file, so the same conditions replaced its contents instead of preserving them.
     *
     * @throws FileException
     */
    public function testASaveThatCannotBeCompletedLeavesTheFileAsItWas(): void
    {
        file_put_contents($this->file, 'the old contents');

        // Where save() wants to put its temporary file.
        mkdir(sprintf('%s.%d.tmp', $this->file, getmypid()));

        $handler = new FileHandler($this->file, 'c+');

        try {
            @$handler->save('the new contents');
            self::fail('a save that cannot write its temporary file has to throw');
        } catch (FileException) {
            // asserted below: what matters is what is left on disk
        } finally {
            @rmdir(sprintf('%s.%d.tmp', $this->file, getmypid()));
        }

        clearstatcache(true, $this->file);

        self::assertSame('the old contents', file_get_contents($this->file));
    }

    /**
     * Replacing the file keeps the permissions it had, rather than giving it the umask's.
     *
     * `config/config.xml` is 0644 with its *directory* held at 0750, and that arrangement is
     * deliberate — a save must not quietly change either half of it.
     *
     * @throws FileException
     */
    public function testSaveKeepsThePermissionsOfTheFileItReplaces(): void
    {
        file_put_contents($this->file, 'contents');
        chmod($this->file, 0640);

        (new FileHandler($this->file, 'c+'))->save('new contents');

        clearstatcache(true, $this->file);

        self::assertSame(0640, fileperms($this->file) & 0777);
    }

    /**
     * And it leaves nothing beside the file it wrote.
     *
     * @throws FileException
     */
    public function testSaveLeavesNoTemporaryFileBehind(): void
    {
        (new FileHandler($this->file, 'c+'))->save('contents');

        self::assertSame([$this->file], glob($this->dir . DIRECTORY_SEPARATOR . '*'));
    }

    /**
     * After a save, this handle still describes what is on disk.
     *
     * It refers to the file that was just replaced, so this is the thing a rename could plausibly
     * have broken: `ConfigFile::isExpired()` compares the config cache against
     * `$this->fileStorage->getFileTime()`, and a stale mtime there would leave the cache looking
     * current after every save. `SplFileInfo` stats the pathname rather than the open descriptor,
     * and `save()` clears the stat cache for it, so both answers follow the rename.
     *
     * @throws FileException
     */
    public function testTheHandleStillDescribesTheFileAfterASave(): void
    {
        file_put_contents($this->file, 'old');
        touch($this->file, time() - 60);

        $handler = new FileHandler($this->file, 'c+');
        $before = $handler->getFileTime();

        $handler->save('new and longer contents');

        self::assertGreaterThan($before, $handler->getFileTime(), 'the mtime must follow the rename');
        self::assertSame(strlen('new and longer contents'), $handler->getFileSize());
    }

    /**
     * @throws FileException
     */
    public function testReadToStringReturnsContent(): void
    {
        file_put_contents($this->file, 'file body');

        self::assertSame('file body', (new FileHandler($this->file))->readToString());
    }

    /**
     * @throws FileException
     */
    public function testReadToStringOnAnEmptyFile(): void
    {
        touch($this->file);

        self::assertSame('', (new FileHandler($this->file))->readToString());
    }

    /**
     * Regression: readToString() must read the file content even when the handle was
     * opened write-only (append mode here, so the existing content is not truncated).
     * Reading through such a handle previously failed with a "Bad file descriptor" notice.
     *
     * @throws FileException
     */
    public function testReadToStringWorksOnAWriteOnlyHandle(): void
    {
        file_put_contents($this->file, 'key material');

        $handler = new FileHandler($this->file, 'a');

        self::assertSame('key material', $handler->readToString());
    }

    public function testReadToStringThrowsWhenFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->readToString();
    }

    /**
     * @throws FileException
     */
    public function testGetFileSize(): void
    {
        file_put_contents($this->file, '12345');

        self::assertSame(5, (new FileHandler($this->file))->getFileSize());
    }

    public function testGetFileSizeThrowsOnZeroWhenRequested(): void
    {
        touch($this->file);

        $this->expectException(FileException::class);

        (new FileHandler($this->file))->getFileSize(true);
    }

    /**
     * @throws FileException
     */
    public function testCheckFileExistsAndReadableReturnSelf(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);

        self::assertSame($handler, $handler->checkFileExists());
        self::assertSame($handler, $handler->checkIsReadable());
    }

    public function testCheckFileExistsThrowsWhenMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->checkFileExists();
    }

    /**
     * @throws FileException
     */
    public function testDeleteRemovesTheFile(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);

        $handler->delete();

        self::assertFileDoesNotExist($this->file);
    }

    /**
     * @throws FileException
     */
    public function testReadYieldsLines(): void
    {
        file_put_contents($this->file, "line1\nline2\n");

        $lines = [];
        foreach ((new FileHandler($this->file))->read() as $line) {
            $lines[] = rtrim($line, "\n");
        }

        self::assertSame(['line1', 'line2'], array_values(array_filter($lines, static fn($l) => $l !== '')));
    }

    /**
     * @throws FileException
     */
    public function testReadFromCsvYieldsRows(): void
    {
        file_put_contents($this->file, "a,b,c\n1,2,3\n");

        $rows = [];
        foreach ((new FileHandler($this->file))->readFromCsv(',') as $row) {
            $rows[] = $row;
        }

        self::assertSame(['a', 'b', 'c'], $rows[0]);
        self::assertSame(['1', '2', '3'], $rows[1]);
    }

    /**
     * @throws FileException
     */
    public function testChmodSetsPermissions(): void
    {
        file_put_contents($this->file, 'x');

        (new FileHandler($this->file))->chmod(0600);

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
    }

    public function testPathAccessors(): void
    {
        file_put_contents($this->file, 'abc');
        $handler = new FileHandler($this->file);

        self::assertSame($this->file, $handler->getFile());
        self::assertSame('test.txt', $handler->getName());
        self::assertSame($this->dir, $handler->getBase());
        self::assertSame(sha1('abc'), $handler->getHash());
    }

    /**
     * open() hands back a second handle on the same path rather than reopening this one, which is
     * how a file written through one handle is then read through another.
     *
     * @throws FileException
     */
    public function testOpenReturnsAHandleOnTheSameFile(): void
    {
        file_put_contents($this->file, 'body');
        $handler = new FileHandler($this->file);

        $opened = $handler->open('rb');

        self::assertNotSame($handler, $opened);
        self::assertSame($this->file, $opened->getFile());
        self::assertSame('body', $opened->readToString());
    }

    /**
     * @throws FileException
     */
    public function testOpenCanTakeALock(): void
    {
        file_put_contents($this->file, 'body');

        self::assertSame('body', (new FileHandler($this->file))->open('rb', true)->readToString());
    }

    /**
     * The constructor of the underlying SplFileObject raises a RuntimeException for a file that is
     * not there; open() turns that into the application's own exception, so a caller does not have
     * to catch both.
     */
    public function testOpenThrowsWhenTheFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->open('rb');
    }

    /**
     * @throws FileException
     */
    public function testCheckIsWritableReturnsSelf(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);

        self::assertSame($handler, $handler->checkIsWritable());
    }

    /**
     * A file that is not there cannot be written to, and the check says so instead of letting the
     * write fail later.
     */
    public function testCheckIsWritableThrowsWhenTheFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->checkIsWritable();
    }

    /**
     * @throws FileException
     */
    public function testGetFileTypeReturnsTheMimeType(): void
    {
        file_put_contents($this->file, "just some text\n");

        self::assertSame('text/plain', (new FileHandler($this->file))->getFileType());
    }

    public function testGetFileTypeThrowsWhenTheFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->getFileType();
    }

    /**
     * @throws FileException
     */
    public function testGetFileTimeReturnsTheModificationTime(): void
    {
        file_put_contents($this->file, 'x');
        touch($this->file, 1700000000);

        self::assertSame(1700000000, (new FileHandler($this->file))->getFileTime());
    }

    public function testGetFileTimeThrowsWhenTheFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        $handler->getFileTime();
    }

    /**
     * save() must not report a write as done when it never got the lock in the first place --
     * otherwise a caller believing its data was persisted could be looking at whatever the file
     * held before. flock() itself has no portable, permission-based way to make it fail (it
     * doesn't care whether the handle is read-only), and forcing a real lock conflict would block
     * rather than fail, since save() calls flock(LOCK_EX) without LOCK_NB. A stream wrapper is the
     * only way to reach this without contriving something that hangs the test instead.
     *
     * @throws FileException
     */
    public function testSaveThrowsWhenTheLockCannotBeObtained(): void
    {
        self::registerFlockFailureStreamWrapper();

        $handler = new FileHandler('sp-flock-failure://obtain/' . uniqid(), 'c+');

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Unable to obtain a lock');

        $handler->save('data');
    }

    /**
     * The matching failure on the way out: the lock is obtained and the data is written, but the
     * unlock itself fails. That must surface too, rather than leaving the caller believing the
     * save completed cleanly while the file is left locked.
     *
     * @throws FileException
     */
    public function testSaveThrowsWhenTheLockCannotBeReleased(): void
    {
        self::registerFlockFailureStreamWrapper();

        $handler = new FileHandler('sp-flock-failure://release/' . uniqid(), 'c+');

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('Unable to release a lock');

        $handler->save('data');
    }

    private static function registerFlockFailureStreamWrapper(): void
    {
        if (!in_array('sp-flock-failure', stream_get_wrappers(), true)) {
            stream_wrapper_register('sp-flock-failure', FileHandlerFlockFailureStreamWrapper::class);
        }
    }

    /**
     * delete() must report a failed unlink() as this application's own exception rather than
     * treating the file as gone. A directory swapped in for the file after opening it is what
     * reaches this reliably: unlink() always refuses a directory (EISDIR), regardless of
     * permissions or which user runs the test -- removing write access would not, if the suite
     * happens to run as root.
     *
     * @throws FileException
     */
    public function testDeleteThrowsWhenUnlinkFails(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);

        unlink($this->file);
        mkdir($this->file);

        $this->expectException(FileException::class);

        try {
            $handler->delete();
        } finally {
            rmdir($this->file);
        }
    }

    /**
     * The stat cache is what makes a file just written still look empty; clearing it is fluent, so
     * it chains in front of the read that needs the fresh size.
     */
    public function testClearCacheIsFluent(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);

        self::assertSame($handler, $handler->clearCache());
    }

    /**
     * Downloads are streamed rather than read into memory, so the whole file has to come out of the
     * chunks in order.
     *
     * @throws FileException
     */
    public function testReadChunkedHandsEveryChunkToTheCallback(): void
    {
        file_put_contents($this->file, str_repeat('abcde', 20));

        $chunks = [];
        (new FileHandler($this->file))->readChunked(static function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        }, 10);

        self::assertGreaterThan(1, count($chunks), 'the file is read in chunks, not in one read');
        self::assertSame(str_repeat('abcde', 20), implode('', $chunks));
    }

    /**
     * A rate above what the memory limit allows is capped rather than honoured, so a caller cannot
     * ask for the whole of a large file at once.
     *
     * @throws FileException
     */
    public function testReadChunkedCapsAnOversizedRate(): void
    {
        file_put_contents($this->file, 'small body');

        $chunks = [];
        (new FileHandler($this->file))->readChunked(static function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        }, PHP_INT_MAX);

        self::assertSame('small body', implode('', $chunks));
    }

    /**
     * With no callback the chunks are printed, which is how a file is handed to the browser.
     *
     * @throws FileException
     */
    public function testReadChunkedPrintsWhenGivenNoCallback(): void
    {
        file_put_contents($this->file, 'printed body');

        $this->expectOutputString('printed body');

        (new FileHandler($this->file))->readChunked(null, 4);
    }

    /**
     * A caller that doesn't pass a rate (e.g. a plain "download this file" call) must still get the
     * app's own throttle instead of skipping the cap entirely — this exercises the branch that picks
     * it from Util::getMaxDownloadChunk() rather than the one that only caps an oversized explicit
     * rate.
     *
     * @throws FileException
     */
    public function testReadChunkedUsesTheDefaultRateWhenNoneIsGiven(): void
    {
        file_put_contents($this->file, 'default rate body');

        $chunks = [];
        (new FileHandler($this->file))->readChunked(static function (string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        self::assertSame('default rate body', implode('', $chunks));
    }

    /**
     * write() must raise the application's own exception rather than let a failed fwrite() pass
     * silently — e.g. code that reuses a handle that was opened for reading only.
     *
     * @throws FileException
     */
    public function testWriteThrowsWhenTheHandleIsReadOnly(): void
    {
        file_put_contents($this->file, 'existing');
        $handler = new FileHandler($this->file, 'r');

        $this->expectException(FileException::class);

        // The underlying fwrite() emits a PHP notice for the failed write; @ mirrors how the
        // production write() call site itself does not (and cannot) suppress it, since the
        // exception below is what the caller is meant to rely on instead.
        @$handler->write('more');
    }

    /**
     * save() truncates and rewrites the file; if the handle cannot be written to (e.g. it was
     * opened read-only) the failure must surface as the application's own exception rather than
     * leaving the caller believing the save succeeded.
     *
     * @throws FileException
     */
    public function testSaveThrowsWhenTheHandleIsReadOnly(): void
    {
        file_put_contents($this->file, 'existing');
        $handler = new FileHandler($this->file, 'r');

        $this->expectException(FileException::class);

        @$handler->save('more');
    }

    /**
     * chmod() has to report a missing file through the same exception every other accessor uses,
     * rather than letting the underlying PHP warning pass unnoticed.
     */
    public function testChmodThrowsWhenTheFileIsMissing(): void
    {
        $handler = new FileHandler($this->file, 'c');
        unlink($this->file);

        $this->expectException(FileException::class);

        @$handler->chmod(0600);
    }

    /**
     * Cleanup code that calls delete() after the file may already have been removed by an earlier
     * step (e.g. a failed operation's own rollback) must not blow up on the second call — delete()
     * is a no-op, not an error, when there is nothing left to delete.
     *
     * @throws FileException
     */
    public function testDeleteIsANoOpWhenTheFileIsAlreadyGone(): void
    {
        file_put_contents($this->file, 'x');
        $handler = new FileHandler($this->file);
        unlink($this->file);

        $result = $handler->delete();

        self::assertSame($handler, $result);
        self::assertFileDoesNotExist($this->file);
    }

    /**
     * getFileTime()'s contract is a non-nullable int, so a falsy modification time (e.g. the Unix
     * epoch itself) must still come back as 0 rather than whatever falsy value getMTime() returned
     * — the `?:` fallback exists precisely so both cases produce the same, always-int, answer.
     *
     * @throws FileException
     */
    public function testGetFileTimeFallsBackToZeroForAFalsyModificationTime(): void
    {
        file_put_contents($this->file, 'x');
        touch($this->file, 0);

        self::assertSame(0, (new FileHandler($this->file))->getFileTime());
    }
}

/**
 * An in-memory stream wrapper that fails exactly one flock() operation, chosen by the URL host
 * ("obtain" or "release"), and otherwise behaves like an ordinary read/write file. This exists
 * solely to reach FileHandler::lock()/unlock()'s error paths (see FileHandlerTest's two
 * testSaveThrowsWhenTheLockCannot* tests): a real filesystem's flock() has no permission-based
 * way to refuse a lock, and forcing an actual conflict would block instead of failing, since
 * save() calls flock() without LOCK_NB.
 */
final class FileHandlerFlockFailureStreamWrapper
{
    private const FAIL_OBTAIN = 'obtain';
    private const FAIL_RELEASE = 'release';

    /** @var resource|null */
    public $context;

    private string $data = '';
    private int $position = 0;
    private string $failing = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->failing = (string)parse_url($path, PHP_URL_HOST);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        $this->data = substr_replace($this->data, $data, $this->position, strlen($data));
        $this->position += strlen($data);

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $base = match ($whence) {
            SEEK_CUR => $this->position,
            SEEK_END => strlen($this->data),
            default => 0,
        };
        $this->position = $base + $offset;

        return true;
    }

    public function stream_truncate(int $newSize): bool
    {
        $this->data = substr($this->data, 0, $newSize);
        $this->position = min($this->position, $newSize);

        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => strlen($this->data),
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }

    public function stream_lock(int $operation): bool
    {
        return match ($operation & 3) {
            LOCK_EX => $this->failing !== self::FAIL_OBTAIN,
            LOCK_UN => $this->failing !== self::FAIL_RELEASE,
            default => true,
        };
    }
}
