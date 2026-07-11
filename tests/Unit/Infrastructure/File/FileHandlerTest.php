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
}
