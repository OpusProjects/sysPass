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

namespace SP\Core\UI;

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\UI\ThemeContextInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Infrastructure\File\FileException;
use SP\Infrastructure\File\FileSystem;

use function SP\processException;

/**
 * Class Theme
 *
 * @package SP
 */
final readonly class Theme implements ThemeInterface
{
    public function __construct(
        private ThemeContextInterface $themeContext,
        private ThemeIconsInterface   $icons
    ) {
    }

    public static function getThemeName(ConfigDataInterface $configData, Context $context): ?string
    {
        $name = $configData->getSiteTheme();

        if ($context->isLoggedIn()) {
            return $context->getUserData()->preferences?->getTheme() ?? $name;
        }

        return $name;
    }

    /**
     * Get the available themes from the themes directory
     */
    public function getAvailable(): array
    {
        $basePath = $this->themeContext->getBasePath();
        $directory = dir($basePath);
        $themesAvailable = [];

        while (false !== ($themeDir = $directory->read())) {
            $themeDirPath = FileSystem::buildPath($basePath, $themeDir);

            if (is_dir($themeDirPath) && $themeDir !== '.' && $themeDir !== '..') {
                try {
                    $themeInfo = FileSystem::require(
                        FileSystem::buildPath($themeDirPath, 'index.php')
                    );

                    if (is_array($themeInfo) && isset($themeInfo['name'])) {
                        $themesAvailable[$themeDir] = $themeInfo['name'];
                    }
                } catch (InvalidClassException|FileException $e) {
                    processException($e);
                }
            }
        }

        $directory->close();

        return $themesAvailable;
    }

    public function getViewsPath(): string
    {
        return $this->themeContext->getViewsPath();
    }

    /**
     * Get the theme information from the info file
     *
     * @return array (
     *          'name' => string
     *          'creator' => string
     *          'version' => string
     *          'js' => array
     *          'css' => array
     *  )
     */
    public function getInfo(): array
    {
        try {
            $themeInfo = FileSystem::require(
                FileSystem::buildPath($this->themeContext->getFullPath(), 'index.php')
            );

            if (is_array($themeInfo)) {
                return $themeInfo;
            }
        } catch (InvalidClassException|FileException $e) {
            processException($e);
        }

        return [];
    }

    public function getUri(): string
    {
        return $this->themeContext->getUri();
    }

    public function getPath(): string
    {
        return $this->themeContext->getPath();
    }

    public function getIcons(): ThemeIconsInterface
    {
        return clone $this->icons;
    }
}
