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
}
