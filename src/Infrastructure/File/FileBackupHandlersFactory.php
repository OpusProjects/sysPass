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

use SP\Domain\File\FileSystem;
use SP\Application\Export\Ports\BackupHandlersFactory;
use SP\Domain\Core\PhpExtensionCheckerService;
use SP\Domain\Export\Dtos\BackupFile as BackupFileDto;
use SP\Domain\Export\Dtos\BackupHandlers;
use SP\Domain\Export\Dtos\BackupType;

/**
 * Builds the file/archive handlers a backup writes to, rooted at $path. The
 * naming matches what the DI container produced before, only parameterised by
 * the target directory so a backup can be written to an arbitrary path.
 */
final readonly class FileBackupHandlersFactory implements BackupHandlersFactory
{
    public function __construct(private PhpExtensionCheckerService $phpExtensionChecker)
    {
    }

    public function build(string $path, string $hash): BackupHandlers
    {
        // Intermediate dump the DB archive is built from. Restrict it to the owner
        // right away: it holds the full plaintext SQL dump (encrypted secrets + the
        // master password hash) and otherwise lives world-readable at the default
        // umask until the archive is built and it is deleted.
        $dbFilePath = FileSystem::buildPath($path, 'database.sql');
        $dbFile = new FileHandler($dbFilePath, 'wb+');
        @chmod($dbFilePath, 0600);

        $dbArchive = new ArchiveHandler(
            (string)new BackupFileDto(BackupType::db, $hash, $path, 'sql'),
            $this->phpExtensionChecker
        );

        $appArchive = new ArchiveHandler(
            (string)new BackupFileDto(BackupType::app, $hash, $path, 'tar'),
            $this->phpExtensionChecker
        );

        return new BackupHandlers($dbFile, $dbArchive, $appArchive);
    }
}
