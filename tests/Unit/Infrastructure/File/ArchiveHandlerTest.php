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

use Phar;
use PharData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\File\ArchiveHandler;
use SP\Infrastructure\PhpExtensionChecker;

/**
 * The backup archives hold the database dump — every account's encrypted secret and the
 * master-password hash — and the application's own `config.xml`, with the database credentials and
 * the crypto keys. They are meant to be readable only by their owner.
 *
 * They were, but only once written: the chmod ran after `buildFromDirectory()` had walked the whole
 * application tree, so on an installation of any size the finished archive sat at the process umask
 * — measured 0644, which on a shared host is every local user — for as long as building it took.
 * A run that died in between left it that way for good.
 *
 * Uses real temporary files: `PharData` writes real archives.
 */
#[Group('unitary')]
class ArchiveHandlerTest extends TestCase
{
    /**
     * The one production caller always passes a regex (`BackupFile::BACKUP_INCLUDE_REGEX`), and so
     * does this — `compressDirectory()`'s `?string $regex = null` default reaches
     * `PharData::buildFromDirectory()`, which requires a string, so the null is a TypeError. It is
     * unreachable today and is not this change's to fix.
     */
    private const EVERYTHING = '/.*/';

    private string $dir;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_archive_', true);
        $this->source = $this->dir . DIRECTORY_SEPARATOR . 'source';

        mkdir($this->source, 0777, true);
        file_put_contents($this->source . DIRECTORY_SEPARATOR . 'secret.txt', str_repeat('x', 4096));
    }

    protected function tearDown(): void
    {
        self::removeRecursively($this->dir);

        parent::tearDown();
    }

    /**
     * The mechanism the fix rests on, pinned on its own.
     *
     * `PharData` gives no way to restrict an archive before its contents land: it does not create
     * the file when it is constructed, so there is nothing to chmod beforehand, and `compress()`
     * refuses outright when its target already exists ("phar ... exists and must be unlinked prior
     * to conversion"). The umask is what is left. If a future PHP stopped honouring it here the
     * archives would go back to being briefly world-readable with nothing else to notice, so this
     * asserts the platform behaviour directly rather than assuming it.
     */
    #[Test]
    public function pharHonoursTheUmaskWhenItCreatesAnArchive(): void
    {
        $tar = $this->dir . DIRECTORY_SEPARATOR . 'probe.tar';

        $umask = umask(0177);

        try {
            $archive = new PharData($tar);
            $archive->buildFromDirectory($this->source);
            $archive->compress(Phar::GZ);
        } finally {
            umask($umask);
        }

        clearstatcache();

        self::assertSame(0600, fileperms($tar) & 0777, 'the tar must be created owner-only');
        self::assertSame(0600, fileperms($tar . '.gz') & 0777, 'and so must the gz');
    }

    /**
     * The archive the handler produces is owner-only, with the ambient umask at its most
     * permissive.
     */
    #[Test]
    public function theCompressedArchiveIsOwnerOnly(): void
    {
        $umask = umask(0);

        try {
            $this->handler()->compressDirectory($this->source, self::EVERYTHING);
        } finally {
            umask($umask);
        }

        clearstatcache();

        // Not the method's return value: it answers `phar:///…/archive.tar.gz/<first entry>`, a
        // URL for a file *inside* the archive rather than the archive's own path. Every caller
        // discards it, so that is a wart rather than a defect, but it is not what to measure.
        self::assertSame(0600, fileperms($this->archivePath()) & 0777);
    }

    /**
     * And nothing is left beside it — the uncompressed tar, which holds exactly the same thing,
     * is removed once the gz exists.
     */
    #[Test]
    public function theUncompressedArchiveDoesNotSurvive(): void
    {
        $this->handler()->compressDirectory($this->source, self::EVERYTHING);

        self::assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'archive.tar');
        self::assertFileExists($this->archivePath());
    }

    private function archivePath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'archive.tar.gz';
    }

    /**
     * And the process is left with the umask it had.
     *
     * The handler narrows it for the duration, so every file the rest of the request creates would
     * be owner-only too if it were not put back — which for the cache and the compiled container
     * would be a change nobody asked for, and one that only shows up later.
     */
    #[Test]
    public function theUmaskIsRestoredAfterwards(): void
    {
        $before = umask();

        $this->handler()->compressDirectory($this->source, self::EVERYTHING);

        self::assertSame($before, umask());
    }

    /**
     * Including when the work throws part-way, which is what the `finally` is for.
     */
    #[Test]
    public function theUmaskIsRestoredWhenTheArchiveCannotBeBuilt(): void
    {
        $before = umask();

        try {
            $this->handler()->compressDirectory($this->dir . DIRECTORY_SEPARATOR . 'no-such-directory', self::EVERYTHING);
        } catch (\Throwable) {
            // asserted below; what matters is the umask the caller is left with
        }

        self::assertSame($before, umask());
    }

    private function handler(): ArchiveHandler
    {
        // The real checker: checkPhar() is a magic method behind an @method docblock, so a stub
        // of the interface does not have it. The extension is present wherever this suite runs.
        return new ArchiveHandler($this->dir . DIRECTORY_SEPARATOR . 'archive', new PhpExtensionChecker());
    }

    private static function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeRecursively($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }
}
