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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * REST API tests for the Config controllers (export / backup).
 */
#[Group('integration')]
class ConfigControllerTest extends ApiTestCase
{
    public function testExportAction(): void
    {
        $r = $this->callApi(AclActionsInterface::CONFIG_EXPORT_RUN, []);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame('Export process finished', $r->body->message);
        $this->assertNotEmpty($r->body->data->files->xml);
        $this->assertFileExists($r->body->data->files->xml);
    }

    public function testExportActionCustomPath(): void
    {
        $path = self::tmpPath() . '/export/custom/path';

        $r = $this->callApi(AclActionsInterface::CONFIG_EXPORT_RUN, ['path' => $path]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame('Export process finished', $r->body->message);
        $this->assertNotEmpty($r->body->data->files->xml);
        $this->assertFileExists($r->body->data->files->xml);
    }

    public function testExportActionInvalidPath(): void
    {
        // A path under /dev/null cannot be created (ENOTDIR), even as root
        $r = $this->callApi(AclActionsInterface::CONFIG_EXPORT_RUN, ['path' => '/dev/null/export/path']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertStringContainsString('Unable to create directory', $r->body->error->message);
    }

    public function testBackupAction(): void
    {
        $r = $this->callApi(AclActionsInterface::CONFIG_BACKUP_RUN, []);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame('Backup process finished', $r->body->message);
        $this->assertNotEmpty($r->body->data->files->app);
        $this->assertNotEmpty($r->body->data->files->db);
        // The compressed archives land in the backup dir
        $appArchives = glob(self::backupPath() . '/sysPass_app-*');
        $dbArchives = glob(self::backupPath() . '/sysPass_db-*');
        $this->assertNotEmpty($appArchives);
        $this->assertNotEmpty($dbArchives);

        // ...and are restricted to the owner: they hold the full DB dump (encrypted
        // secrets + master password hash) and the app config, so must not be world-readable.
        foreach (array_merge($appArchives, $dbArchives) as $archive) {
            $this->assertSame(0600, fileperms($archive) & 0777, $archive . ' must be owner-only');
        }
    }

    public function testBackupActionInvalidPath(): void
    {
        // A path under /dev/null cannot be created (ENOTDIR), even as root
        $r = $this->callApi(AclActionsInterface::CONFIG_BACKUP_RUN, ['path' => '/dev/null/backup/path']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertStringContainsString('Error while doing the backup', $r->body->error->message);
    }

    public function testBackupActionCustomPath(): void
    {
        $path = self::tmpPath() . '/backup/custom/path';
        mkdir($path, 0777, true);

        $r = $this->callApi(AclActionsInterface::CONFIG_BACKUP_RUN, ['path' => $path]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame('Backup process finished', $r->body->message);
        $this->assertNotEmpty(glob($path . '/sysPass_app-*'));
        $this->assertNotEmpty(glob($path . '/sysPass_db-*'));
    }
}
