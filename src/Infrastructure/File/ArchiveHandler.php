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
use Phar;
use PharData;
use SP\Domain\Core\PhpExtensionCheckerService;
use SP\Domain\File\Ports\ArchiveHandlerInterface;

/**
 * Class ArchiveHandler
 */
class ArchiveHandler implements ArchiveHandlerInterface
{
    /**
     * The umask held while the archives are written, so they are private from the moment they
     * exist rather than from the moment the chmod runs.
     *
     * `PharData` gives no way to do this per file: it does not create the archive when it is
     * constructed, so there is nothing to restrict beforehand, and `compress()` refuses outright
     * when its target already exists — "phar ... exists and must be unlinked prior to conversion".
     * The umask is what is left, and it covers both the tar and the gz.
     */
    private const OWNER_ONLY = 0177;

    private readonly PharData $archive;

    public function __construct(string $archive, PhpExtensionCheckerService $phpExtensionCheckerService)
    {
        $phpExtensionCheckerService->checkPhar(true);

        $this->archive = new PharData(self::makeArchiveName($archive));
    }

    private static function makeArchiveName(string $archive): string
    {
        // return preg_replace('/\.gz$/', '', $archive);
        return sprintf('%s.tar', $archive);
    }

    /**
     * Create a backup of the application and compress it.
     *
     * @throws FileException
     */
    public function compressDirectory(string $directory, ?string $regex = null): string
    {
        $umask = umask(self::OWNER_ONLY);

        try {
            $this->archive->buildFromDirectory($directory, $regex);

            // Before compressing, not only after: the uncompressed archive holds the same thing
            // the compressed one does and exists for as long as compressing takes, which on a
            // large installation is not an instant. `database.sql` is restricted the moment it is
            // opened for exactly this reason; this is the same window, left open.
            $this->restrictToOwner($this->archive->getPath());

            $packed = $this->archive->compress(Phar::GZ);

            // Delete the non-compressed archive
            (new FileHandler($this->archive->getPath()))->delete();

            $this->restrictToOwner($this->archive->getPath() . '.gz');
        } finally {
            umask($umask);
        }

        return $packed->getFileInfo()->getPathname();
    }

    /**
     * Create a backup of the application and compress it.
     *
     * @throws FileException
     */
    public function compressFile(string $file): string
    {
        $umask = umask(self::OWNER_ONLY);

        try {
            $this->archive->addFile($file, basename($file));

            // See compressDirectory(): the uncompressed archive is as sensitive as the compressed
            // one and lives for as long as compressing takes.
            $this->restrictToOwner($this->archive->getPath());

            $packed = $this->archive->compress(Phar::GZ);

            // Delete the non-compressed files
            (new FileHandler($file))->delete();
            (new FileHandler($this->archive->getPath()))->delete();

            $this->restrictToOwner($this->archive->getPath() . '.gz');
        } finally {
            umask($umask);
        }

        return $packed->getFileInfo()->getPathname();
    }

    /**
     * Restrict a backup archive to its owner.
     *
     * The archive holds a full DB dump (encrypted account blobs + the master
     * password hash) or the application files (config.xml with the crypto keys),
     * so it must not land at the process default umask (typically world-readable
     * 0644) where any local user on a shared host could read it. That applies to
     * the uncompressed archive too, for as long as it exists.
     */
    private function restrictToOwner(string $path): void
    {
        @chmod($path, 0600);
    }
}
