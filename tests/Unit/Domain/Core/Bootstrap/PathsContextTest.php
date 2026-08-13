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

namespace SP\Tests\Unit\Domain\Core\Bootstrap;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Bootstrap\PathsContext;
use ValueError;

/**
 * Class PathsContextTest
 *
 * PathsContext is the single source of truth the DI container and every controller read
 * filesystem locations from (backup dir, log file, cache dir, ...). A wrong answer here means
 * the app reads or writes the wrong directory somewhere in production.
 */
#[Group('unitary')]
class PathsContextTest extends TestCase
{
    /**
     * offsetExists backs `isset($pathsContext[Path::X])` checks. If it always reported true
     * (or always false) callers could not tell a configured path apart from a missing one.
     */
    public function testOffsetExistsReflectsWhetherAPathWasRegistered(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::APP, '/var/www/app');

        self::assertTrue($pathsContext->offsetExists(Path::APP));
        self::assertFalse($pathsContext->offsetExists(Path::TMP));
    }

    /**
     * Controllers and DI factories read paths via array access (`$pathsContext[Path::BACKUP]`).
     * If offsetGet did not proxy to the underlying storage, every one of those reads would break.
     */
    public function testOffsetGetReturnsTheValueRegisteredForThePath(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::BACKUP, '/var/www/backup');

        self::assertSame('/var/www/backup', $pathsContext->offsetGet(Path::BACKUP));
    }

    /**
     * `$pathsContext[Path::X] = $value` (ArrayAccess::offsetSet) is the array-assignment form of
     * registering a path used when the container builds this object from config. It must behave
     * exactly like addPath(), duplicate rejection included, or a config bug could silently
     * overwrite a path instead of failing loudly.
     */
    public function testOffsetSetRegistersAPathLikeAddPathDoes(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->offsetSet(Path::CACHE, '/var/www/cache');

        self::assertSame('/var/www/cache', $pathsContext->offsetGet(Path::CACHE));

        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Duplicated path found: CACHE');

        $pathsContext->offsetSet(Path::CACHE, '/var/www/other-cache');
    }

    /**
     * Every Path case may only be registered once. Silently accepting a second registration
     * would let one part of bootstrap overwrite another's path without anyone noticing.
     */
    public function testAddPathRejectsARepeatedRegistrationOfTheSamePath(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::LOG_FILE, '/var/log/syspass.log');

        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Duplicated path found: LOG_FILE');

        $pathsContext->addPath(Path::LOG_FILE, '/var/log/other.log');
    }

    /**
     * addPaths() is how CoreDefinitions seeds every configured path from the DI container in one
     * call. If it did not forward each [Path, value] pair to addPath(), the application would
     * boot with none of its filesystem paths configured.
     */
    public function testAddPathsRegistersEveryPairItIsGiven(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->addPaths(
            [
                [Path::APP, '/var/www/app'],
                [Path::TMP, '/var/www/tmp'],
            ]
        );

        self::assertSame('/var/www/app', $pathsContext->offsetGet(Path::APP));
        self::assertSame('/var/www/tmp', $pathsContext->offsetGet(Path::TMP));
    }

    /**
     * Each entry passed to addPaths() must itself be a [Path, value] array. Accepting a
     * malformed entry silently would push the failure down into addPath()'s variadic spread
     * with a confusing argument-count error instead of a clear one.
     */
    public function testAddPathsRejectsAnEntryThatIsNotAnArray(): void
    {
        $pathsContext = new PathsContext();

        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Path spec must be an array');

        $pathsContext->addPaths(['not-an-array-spec']);
    }

    /**
     * offsetUnset backs `unset($pathsContext[Path::X])`. Once a path is unset, offsetExists
     * must report it as gone rather than leaving a stale entry other code could keep reading.
     */
    public function testOffsetUnsetRemovesARegisteredPath(): void
    {
        $pathsContext = new PathsContext();
        $pathsContext->addPath(Path::VIEW, '/var/www/views');

        $pathsContext->offsetUnset(Path::VIEW);

        self::assertFalse($pathsContext->offsetExists(Path::VIEW));
    }
}
