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

namespace SP\Tests\Unit\Infrastructure\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Infrastructure\Context\ContextBase;
use SP\Infrastructure\UI\ThemeIcons;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\UI\ThemeContextInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Domain\Core\UI\FontIcon;
use SP\Domain\Core\Exceptions\FileException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class ThemeIconsTest
 *
 */
#[Group('unitary')]
class ThemeIconsTest extends UnitaryTestCase
{
    public function testGetIconByNameWithUnknownIcon()
    {
        $themeIcons = new ThemeIcons();
        $out = $themeIcons->getIconByName('test');

        $this->assertInstanceOf(FontIcon::class, $out);
        $this->assertEquals('test', $out->getIcon());
        $this->assertEquals('mdl-color-text--indigo-A200', $out->getClass());
    }

    public function testGetIconByName()
    {
        $themeIcons = new ThemeIcons();
        $themeIcons->addIcon('test', new FontIcon('test', 'testClass', 'testTitle'));

        $out = $themeIcons->getIconByName('test');

        $this->assertInstanceOf(FontIcon::class, $out);
        $this->assertEquals('test', $out->getIcon());
        $this->assertEquals('testClass', $out->getClass());
        $this->assertEquals('testTitle', $out->getTitle());
    }

    public function testAddIcon()
    {
        $themeIcons = new ThemeIcons();
        $themeIcons->addIcon('test', new FontIcon('test', 'testClass', 'testTitle'));

        $out = $themeIcons->getIconByName('test');

        $this->assertInstanceOf(FontIcon::class, $out);
        $this->assertEquals('test', $out->getIcon());
        $this->assertEquals('testClass', $out->getClass());
        $this->assertEquals('testTitle', $out->getTitle());
    }

    /**
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testLoadIconsWithCache()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);
        $themeContext->expects($this->never())
                     ->method('getFullPath');

        $context->expects(self::once())
                ->method('getAppStatus')
                ->willReturn('test');

        $fileCache->expects(self::once())
                  ->method('isExpired')
            ->willReturn(false);

        // loadWith(), not load(): the cache is read into the classes it is allowed to contain.
        $fileCache->expects(self::once())
                  ->method('loadWith')
                  ->with(ThemeIcons::class, FontIcon::class)
                  ->willReturn(new ThemeIcons());

        ThemeIcons::loadIcons($context, $fileCache, $themeContext);
    }

    /**
     * A cache written by a version that named other classes is rebuilt, not thrown.
     *
     * Naming the classes means loadWith() refuses anything else, and refusing is an exception —
     * where the old code got back whatever the file held and fell through to a rebuild. That
     * graceful path is the reason naming them costs nothing, so it is kept: an unreadable cache
     * regenerates from the theme rather than taking the page down.
     *
     * This supersedes a test that had the cache hand back a stdClass. loadWith() cannot do that —
     * it returns the class it was asked for or throws — so that test described a state the code
     * can no longer reach.
     *
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testACacheThatCannotBeReadIntoTheExpectedClassesIsRebuilt()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $context->expects(self::once())->method('getAppStatus')->willReturn('test');
        $fileCache->expects(self::once())->method('isExpired')->willReturn(false);

        $fileCache->expects(self::once())
                  ->method('loadWith')
                  ->willThrowException(InvalidClassException::error('stale'));

        $fileCache->expects(self::once())
                  ->method('save')
                  ->with(self::isInstanceOf(ThemeIconsInterface::class));

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue');

        $out = ThemeIcons::loadIcons($context, $fileCache, $themeContext);

        $this->assertInstanceOf(ThemeIconsInterface::class, $out);
        $this->assertEquals('add', $out->add()->getIcon());
    }

    /**
     * A cached set whose icons did not survive being read is rebuilt too.
     *
     * The icons live in an array, and an array holds __PHP_Incomplete_Class quietly — so a
     * ThemeIcons whose entries were not among the named classes still satisfies instanceof while
     * containing nothing usable. Checking the class the cache was read into is not enough; the
     * contents are checked as well.
     *
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testACachedSetWithIconsThatDidNotSurviveIsRebuilt()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $hollow = new ThemeIcons();
        $hollow->addIcon('add', new FontIcon('add'));

        // What unserialize() leaves behind when a nested class was not allowed.
        $incomplete = @unserialize('O:22:"SomeClassThatIsNotHere":0:{}', ['allowed_classes' => false]);
        (function () use ($incomplete) {
            $this->icons['add'] = $incomplete;
        })->call($hollow);

        $context->expects(self::once())->method('getAppStatus')->willReturn('test');
        $fileCache->expects(self::once())->method('isExpired')->willReturn(false);
        $fileCache->expects(self::once())->method('loadWith')->willReturn($hollow);

        $fileCache->expects(self::once())
                  ->method('save')
                  ->with(self::isInstanceOf(ThemeIconsInterface::class));

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue');

        $out = ThemeIcons::loadIcons($context, $fileCache, $themeContext);

        $this->assertEquals('add', $out->add()->getIcon());
    }

    /**
     * When the app status is "reloaded" (e.g. after an upgrade or a config change),
     * a previously cached icon set must never be reused, or the theme would keep
     * showing icons from before the reload. The cache check must be skipped
     * entirely and the icons re-read from the theme's Icons.php file.
     *
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testLoadIconsWhenAppStatusReloadedSkipsCacheAndLoadsFromFile()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $context->expects(self::once())
                ->method('getAppStatus')
                ->willReturn(ContextBase::APP_STATUS_RELOADED);

        $fileCache->expects(self::never())
                  ->method('isExpired');
        $fileCache->expects(self::never())
                  ->method('loadWith');
        $fileCache->expects(self::once())
                  ->method('save')
                  ->with(self::isInstanceOf(ThemeIconsInterface::class));

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue');

        $out = ThemeIcons::loadIcons($context, $fileCache, $themeContext);

        $this->assertInstanceOf(ThemeIconsInterface::class, $out);
        $this->assertEquals('add', $out->add()->getIcon());
    }

    /**
     * An expired cache must not be trusted: stale icon definitions (e.g. after a
     * theme update changed an icon glyph) would otherwise keep being served
     * until the cache file happens to be regenerated for another reason.
     *
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testLoadIconsWhenCacheExpiredLoadsFromFile()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $context->expects(self::once())
                ->method('getAppStatus')
                ->willReturn('test');

        $fileCache->expects(self::once())
                  ->method('isExpired')
                  ->willReturn(true);
        $fileCache->expects(self::never())
                  ->method('loadWith');
        $fileCache->expects(self::once())
                  ->method('save')
                  ->with(self::isInstanceOf(ThemeIconsInterface::class));

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue');

        $out = ThemeIcons::loadIcons($context, $fileCache, $themeContext);

        $this->assertInstanceOf(ThemeIconsInterface::class, $out);
        $this->assertEquals('warning', $out->warning()->getIcon());
    }


    /**
     * A missing/misconfigured theme (e.g. an incomplete custom theme install)
     * must fail loudly instead of silently serving a broken page: the
     * FileException from FileSystem::require() has to propagate to the caller
     * after being logged, not be swallowed.
     *
     * @throws Exception
     */
    public function testLoadIconsThrowsFileExceptionWhenThemeFileIsMissing()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $context->expects(self::once())
                ->method('getAppStatus')
                ->willReturn(ContextBase::APP_STATUS_RELOADED);

        $fileCache->expects(self::never())
                  ->method('save');

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/does-not-exist-theme-dir');

        $this->expectException(FileException::class);

        ThemeIcons::loadIcons($context, $fileCache, $themeContext);
    }
}
