<?php

declare(strict_types=1);
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

namespace SP\Infrastructure\File;

use SP\Domain\Core\Exceptions\FileException;
use RuntimeException;
use SP\Domain\File\Ports\FileHandlerInterface;
use SP\Infrastructure\Util\Util;
use SplFileObject;
use ValueError;

use function SP\__;
use function SP\__u;
use function SP\logger;

/**
 * Class FileHandler
 */
final class FileHandler extends SplFileObject implements FileHandlerInterface
{
    public const  CHUNK_FACTOR = 3;

    /**
     * @inheritDoc
     */
    /**
     * The mode is kept because `save()` no longer writes through this handle — it replaces the
     * file by renaming a sibling over it, which the directory's permissions allow whatever this
     * handle was opened for. Refusing a read-only handle has to be explicit now that the stream
     * will not refuse it for us.
     */
    private readonly string $mode;

    /**
     * @inheritDoc
     */
    public function __construct(private readonly string $file, string $mode = 'r')
    {
        $this->mode = $mode;

        parent::__construct($this->file, $mode);
    }

    /**
     * Writes data into file
     *
     * @throws FileException
     */
    public function write(string $data): FileHandlerInterface
    {
        if ($this->fwrite($data) === false) {
            throw FileException::error(sprintf(__('Unable to read/write the file (%s)'), $this->file));
        }

        return $this;
    }

    /**
     * Reads data from file into a string
     *
     * @throws FileException
     */
    public function readToString(): string
    {
        // Read by path rather than through this handle: it may have been opened
        // write-only (e.g. the CryptPKI key files), in which case fread() on it
        // fails with a "Bad file descriptor" notice. file_get_contents() also
        // handles a zero-length file cleanly.
        $data = @file_get_contents($this->file);

        if ($data === false) {
            throw FileException::error(sprintf(__('Unable to read from file (%s)'), $this->file));
        }

        return $data;
    }

    /**
     * @return void
     */
    private function autoDetectEOL(): void
    {
        // PHP >= 8.1 reads all line-ending styles natively; the auto_detect_line_endings
        // ini flag this used to toggle is deprecated (removed in PHP 9) and a no-op.
    }

    /**
     * Reads data from a CSV file
     *
     * @throws FileException
     */
    public function readFromCsv(string $delimiter): iterable
    {
        $this->autoDetectEOL();

        while (!$this->eof()) {
            $data = $this->fgetcsv($delimiter, '"', '\\');

            if ($data === false) {
                throw FileException::error(__u('Error while reading the CSV file file'), $this->file);
            }

            yield $data;
        }
    }

    /**
     * Reads data from a file line by line
     */
    public function read(): iterable
    {
        $this->autoDetectEOL();

        while (!$this->eof()) {
            yield $this->fgets();
        }
    }

    /**
     * Saves a string into a file
     *
     * @throws FileException
     */
    public function save(string $data): FileHandlerInterface
    {
        // `r` without `+` is the only read-only family; w, a, x and c all open for writing.
        if (str_starts_with($this->mode, 'r') && !str_contains($this->mode, '+')) {
            throw FileException::error(sprintf(__('Unable to read/write the file (%s)'), $this->file));
        }

        $this->lock();

        try {
            $this->replaceWith($data);
        } finally {
            $this->unlock();
        }

        return $this;
    }

    /**
     * Put `$data` in place of this file's contents, without the file ever being partly written.
     *
     * This used to be `ftruncate(0)` followed by `fwrite()`, which has a window in which the file
     * is empty on disk. `config.xml` goes through here, and it holds the database credentials, the
     * password salt and the master-password hash — so a process killed in that window (an OOM, a
     * container stopped mid-save, the host losing power) left an installation that cannot boot and
     * cannot be recovered through the UI, because the container is built before `Init` runs and the
     * install route refuses once `<installed>` was set. Nothing takes a backup first:
     * `ConfigBackupService::backup()` exists and is called from nowhere.
     *
     * A sibling temp file renamed into place cannot show a half-written state to anybody, because
     * `rename()` within a filesystem is atomic. That is what readers need, and the lock was never
     * going to give it to them: `XmlFileStorage::load()` hands the *path* to `DOMDocument`, and
     * `readToString()` reads by path too, so neither has ever taken this handle's lock. The lock
     * stays because it still orders two writers that hold the same open file, and because losing
     * one of two concurrent saves is a different and much smaller problem than losing the file.
     *
     * The temp file is created private and given the target's own permissions before the rename,
     * so the mode does not change and the contents are never briefly readable at the umask.
     *
     * @throws FileException
     */
    private function replaceWith(string $data): void
    {
        // Per-process, so two of them cannot write into each other's temp file.
        $temp = sprintf('%s.%d.tmp', $this->file, getmypid());

        $mode = file_exists($this->file)
            ? (fileperms($this->file) & 0777)
            : (0666 & ~umask());

        $handle = @fopen($temp, 'wb');

        if ($handle === false) {
            throw FileException::error(sprintf(__('Unable to read/write the file (%s)'), $this->file));
        }

        try {
            @chmod($temp, 0600);

            if (fwrite($handle, $data) === false) {
                throw FileException::error(sprintf(__('Unable to read/write the file (%s)'), $this->file));
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }

        try {
            @chmod($temp, $mode);

            if (!@rename($temp, $this->file)) {
                throw FileException::error(sprintf(__('Unable to read/write the file (%s)'), $this->file));
            }
        } catch (FileException $e) {
            @unlink($temp);

            throw $e;
        }

        // This handle still refers to the file that was just replaced. Nothing reads through it —
        // every read in this class goes by path — but clearing the stat cache keeps getFileSize()
        // and getFileTime() answering about what is now on disk.
        clearstatcache(true, $this->file);
    }

    /**
     * Lock the file
     *
     * @throws FileException
     */
    private function lock(): void
    {
        if (!$this->flock(LOCK_EX)) {
            throw FileException::error(sprintf(__('Unable to obtain a lock (%s)'), $this->file));
        }

        logger(sprintf('File locked: %s', $this->file));
    }

    /**
     * Lock the file
     *
     * @throws FileException
     */
    private function unlock(): void
    {
        if (!$this->flock(LOCK_UN)) {
            throw FileException::error(sprintf(__('Unable to release a lock (%s)'), $this->file));
        }

        logger(sprintf('File unlocked: %s', $this->file));
    }

    /**
     * @param callable|null $chunker
     * @param float|null $rate
     *
     * @throws FileException
     */
    public function readChunked(?callable $chunker = null, ?float $rate = null): void
    {
        $maxRate = Util::getMaxDownloadChunk() / self::CHUNK_FACTOR;

        if ($rate === null || $rate > $maxRate) {
            $rate = (float)$maxRate;
        }

        while (!$this->eof()) {
            if ($chunker !== null) {
                $chunker($this->fread((int)round($rate)));
            } else {
                print $this->fread((int)round($rate));
                ob_flush();
                flush();
            }
        }
    }

    /**
     * Opens the file
     *
     * @return FileHandler
     * @throws FileException
     */
    public function open(string $mode = 'rb', ?bool $lock = false): FileHandlerInterface
    {
        try {
            $file = new self($this->file, $mode);

            if ($lock) {
                $file->lock();
            }
        } catch (RuntimeException) {
            throw FileException::error(sprintf(__('Unable to open the file (%s)'), $this->file));
        }

        return $file;
    }

    /**
     * Checks if the file is writable
     *
     * @throws FileException
     */
    public function checkIsWritable(): FileHandlerInterface
    {
        if (!$this->isWritable()) {
            throw FileException::error(sprintf(__('Unable to write in file (%s)'), $this->file));
        }

        return $this;
    }

    /**
     * Checks if the file exists
     *
     * @throws FileException
     */
    public function checkFileExists(): FileHandlerInterface
    {
        if (!$this->isReadable()) {
            throw FileException::error(sprintf(__('File not found (%s)'), $this->file));
        }

        return $this;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    /**
     * @throws FileException
     */
    public function getFileSize(bool $isExceptionOnZero = false): int
    {
        $size = $this->getSize();

        if ($size === false
            || ($isExceptionOnZero === true && $size === 0)
        ) {
            throw FileException::error(sprintf(__('Unable to read/write file (%s)'), $this->file));
        }

        return $size;
    }

    /**
     * Clears the stat cache for the given file
     */
    public function clearCache(): FileHandlerInterface
    {
        clearstatcache(true, $this->file);

        return $this;
    }

    /**
     * Deletes a file
     *
     * @throws FileException
     */
    public function delete(): FileHandlerInterface
    {
        if (file_exists($this->file) && @unlink($this->file) === false) {
            throw FileException::error(sprintf(__('Unable to delete file (%s)'), $this->file));
        }

        return $this;
    }

    /**
     * Returns the content type in MIME format
     *
     * @throws FileException
     */
    public function getFileType(): string
    {
        $this->checkIsReadable();

        return mime_content_type($this->file);
    }

    /**
     * Checks if the file is readable
     *
     * @throws FileException
     */
    public function checkIsReadable(): FileHandlerInterface
    {
        if (!$this->isReadable()) {
            throw FileException::error(sprintf(__('Unable to read/write file (%s)'), $this->file));
        }

        return $this;
    }

    /**
     * @throws FileException
     */
    public function getFileTime(): int
    {
        $this->checkIsReadable();

        return $this->getMTime() ?: 0;
    }

    /**
     * @param int $permissions Octal permissions
     *
     * @return FileHandlerInterface
     * @throws FileException
     */
    public function chmod(int $permissions): FileHandlerInterface
    {
        if (chmod($this->file, $permissions) === false) {
            throw FileException::error(sprintf(__('Unable to set permissions for file (%s)'), $this->file));
        }

        return $this;
    }

    public function getBase(): string
    {
        return dirname($this->file);
    }

    public function getName(): string
    {
        return basename($this->file);
    }

    public function getHash(): string
    {
        return sha1_file($this->file);
    }
}
