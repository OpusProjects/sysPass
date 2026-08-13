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

        $fileCache->expects(self::once())
                  ->method('load')
                  ->willReturn(new ThemeIcons());

        ThemeIcons::loadIcons($context, $fileCache, $themeContext);
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
                  ->method('load');
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
                  ->method('load');
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
     * A cache file can only ever hold data that unserialize() was able to turn
     * back into an object. If a class was renamed/removed since the file was
     * written, load() hands back something that is not a ThemeIconsInterface;
     * blindly returning it would crash every page that renders an icon.
     * Instead the code must log and rebuild from the theme file.
     *
     * @throws InvalidClassException
     * @throws Exception
     * @throws FileException
     */
    public function testLoadIconsWhenCachedValueIsStaleClassRebuildsFromFile()
    {
        $context = $this->createMock(Context::class);
        $fileCache = $this->createMock(FileCacheService::class);
        $themeContext = $this->createMock(ThemeContextInterface::class);

        $context->expects(self::once())
                ->method('getAppStatus')
                ->willReturn('test');

        $fileCache->expects(self::once())
                  ->method('isExpired')
                  ->willReturn(false);
        $fileCache->expects(self::once())
                  ->method('load')
                  ->willReturn(new \stdClass());
        $fileCache->expects(self::once())
                  ->method('save')
                  ->with(self::isInstanceOf(ThemeIconsInterface::class));

        $themeContext->expects(self::once())
                     ->method('getFullPath')
                     ->willReturn(REAL_APP_ROOT . '/public/themes/material-blue');

        $out = ThemeIcons::loadIcons($context, $fileCache, $themeContext);

        $this->assertInstanceOf(ThemeIconsInterface::class, $out);
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
