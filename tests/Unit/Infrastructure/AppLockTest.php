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

namespace SP\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Infrastructure\AppLock;

/**
 * Class AppLockTest
 *
 * Uses a real temporary path: AppLock's lock file is opened through FileHandler,
 * which extends SplFileObject and opens the file eagerly in its constructor, so it
 * can't be exercised reliably against vfsStream.
 */
#[Group('unitary')]
class AppLockTest extends TestCase
{
    private string $dir;
    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sp_applock_', true);
        mkdir($this->dir);
        $this->lockFile = $this->dir . DIRECTORY_SEPARATOR . '.lock';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Regression: lock() previously used FileHandler's default 'r' mode, which requires the
     * file to already exist. The lock file (config/.lock) is never pre-created, so the very
     * first call crashed with an uncaught RuntimeException instead of creating it.
     */
    public function testLockCreatesTheLockFileWhenMissing(): void
    {
        self::assertFileDoesNotExist($this->lockFile);

        $appLock = new AppLock($this->lockFile);
        $appLock->lock(100, 'testing');

        self::assertFileExists($this->lockFile);
    }

    public function testGetLockReturnsTheLockingUserId(): void
    {
        $appLock = new AppLock($this->lockFile);
        $appLock->lock(100, 'testing');

        self::assertSame(100, $appLock->getLock());
    }

    public function testGetLockReturnsFalseWhenNotLocked(): void
    {
        $appLock = new AppLock($this->lockFile);

        self::assertFalse($appLock->getLock());
    }

    public function testUnlockRemovesTheLockFile(): void
    {
        $appLock = new AppLock($this->lockFile);
        $appLock->lock(100, 'testing');

        self::assertFileExists($this->lockFile);

        $appLock->unlock();

        self::assertFileDoesNotExist($this->lockFile);
    }

    /**
     * Regression: a shorter new payload must not leave trailing bytes from a previous
     * longer one behind — save() truncates before writing, so a second, shorter lock()
     * call must fully replace the file's contents rather than append to them.
     */
    public function testLockOverwritesAPreviousLongerPayload(): void
    {
        $appLock = new AppLock($this->lockFile);
        $appLock->lock(100, 'a much longer subject than the second call uses');
        $appLock->lock(2, 'x');

        self::assertSame(2, $appLock->getLock());
    }
}
