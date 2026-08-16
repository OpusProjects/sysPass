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

namespace SP\Infrastructure\UI;

use SP\Infrastructure\Context\ContextBase;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\Core\UI\ThemeContextInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Domain\Core\UI\FontIcon;
use SP\Domain\Core\UI\IconInterface;
use SP\Domain\Core\Exceptions\FileException;
use SP\Domain\File\FileSystem;

use function SP\logger;
use function SP\processException;

/**
 * Class ThemeIcons
 *
 */
final class ThemeIcons implements ThemeIconsInterface
{
    public const CACHE_EXPIRE     = 86400;
    public const ICONS_CACHE_FILE = 'icons.cache';

    /**
     * @var IconInterface[]
     */
    private array $icons = [];

    /**
     * @param Context $context
     * @param FileCacheService $cache
     * @param ThemeContextInterface $themeContext
     * @return ThemeIconsInterface
     * @throws InvalidClassException
     * @throws FileException
     */
    public static function loadIcons(
        Context          $context,
        FileCacheService $cache,
        ThemeContextInterface $themeContext
    ): ThemeIconsInterface {
        try {
            if ($context->getAppStatus() !== ContextBase::APP_STATUS_RELOADED
                && !$cache->isExpired(self::CACHE_EXPIRE)
            ) {
                try {
                    // Named, rather than checked after the fact: the instanceof test that used to
                    // stand here only ran once the file had already been turned into objects,
                    // which is too late to be a guard. FontIcon is named too — the icons are
                    // nested inside, and allowing only the outer class would hand back a
                    // ThemeIcons full of __PHP_Incomplete_Class that still passes instanceof.
                    $cached = $cache->loadWith(self::class, FontIcon::class);

                    // No instanceof here: loadWith() is declared to return the class it was
                    // given and throws otherwise, so the only thing left to establish is that the
                    // icons inside survived.
                    if (self::holdsOnlyIcons($cached)) {
                        return $cached;
                    }

                    logger('Icons cache contains stale class — rebuilding', 'INFO');
                } catch (InvalidClassException) {
                    // A cache written by a version that named different classes. Rebuilding from
                    // the theme is what this already did for a stale class, and is why naming the
                    // classes costs nothing: anything unexpected is regenerated rather than served.
                    logger('Icons cache cannot be read into the expected classes — rebuilding', 'INFO');
                }
            }

            $icons = FileSystem::require(
                FileSystem::buildPath($themeContext->getFullPath(), 'inc', 'Icons.php'),
                ThemeIconsInterface::class
            );

            $cache->save($icons);

            logger('Saved icons cache', 'INFO');

            return $icons;
        } catch (FileException $e) {
            processException($e);

            throw $e;
        }
    }

    /**
     * Whether every icon survived deserialisation as an icon.
     *
     * An icon class this one does not name comes back as __PHP_Incomplete_Class, and the object
     * around it still satisfies instanceof — so the contents are checked rather than assumed, and
     * a cache that fails is rebuilt from the theme instead of served half-formed.
     */
    private static function holdsOnlyIcons(self $icons): bool
    {
        // Reading the private property of another instance of the same class, which PHP scopes by
        // class rather than by object — there is no accessor, and adding one to the interface for
        // a cache check would be the wrong place to put it.
        foreach ($icons->icons as $icon) {
            // Statically this cannot fail — every write to the array goes through addIcon(), which
            // takes an IconInterface. Deserialisation does not go through it: an icon class that
            // was not named comes back as __PHP_Incomplete_Class and sits in the array regardless,
            // because an array has no element type to enforce at runtime.
            /** @phpstan-ignore instanceof.alwaysTrue */
            if (!$icon instanceof IconInterface) {
                return false;
            }
        }

        return true;
    }

    public function getIconByName(string $name): IconInterface
    {
        return $this->icons[$name] ?? new FontIcon($name, 'mdl-color-text--indigo-A200');
    }

    public function addIcon(string $alias, IconInterface $icon): void
    {
        $this->icons[$alias] = $icon;
    }

    public function warning(): IconInterface
    {
        return $this->getIconByName('warning');
    }
    public function download(): IconInterface
    {
        return $this->getIconByName('download');
    }
    public function clear(): IconInterface
    {
        return $this->getIconByName('clear');
    }
    public function play(): IconInterface
    {
        return $this->getIconByName('play');
    }
    public function help(): IconInterface
    {
        return $this->getIconByName('help');
    }
    public function publicLink(): IconInterface
    {
        return $this->getIconByName('publicLink');
    }
    public function back(): IconInterface
    {
        return $this->getIconByName('back');
    }
    public function restore(): IconInterface
    {
        return $this->getIconByName('restore');
    }
    public function save(): IconInterface
    {
        return $this->getIconByName('save');
    }
    public function up(): IconInterface
    {
        return $this->getIconByName('up');
    }
    public function down(): IconInterface
    {
        return $this->getIconByName('down');
    }
    public function viewPass(): IconInterface
    {
        return $this->getIconByName('viewPass');
    }
    public function copy(): IconInterface
    {
        return $this->getIconByName('copy');
    }
    public function clipboard(): IconInterface
    {
        return $this->getIconByName('clipboard');
    }
    public function email(): IconInterface
    {
        return $this->getIconByName('email');
    }
    public function refresh(): IconInterface
    {
        return $this->getIconByName('refresh');
    }
    public function editPass(): IconInterface
    {
        return $this->getIconByName('editPass');
    }
    public function appAdmin(): IconInterface
    {
        return $this->getIconByName('appAdmin');
    }
    public function accAdmin(): IconInterface
    {
        return $this->getIconByName('accAdmin');
    }
    public function ldapUser(): IconInterface
    {
        return $this->getIconByName('ldapUser');
    }
    public function disabled(): IconInterface
    {
        return $this->getIconByName('disabled');
    }
    public function navPrev(): IconInterface
    {
        return $this->getIconByName('navPrev');
    }
    public function navNext(): IconInterface
    {
        return $this->getIconByName('navNext');
    }
    public function navFirst(): IconInterface
    {
        return $this->getIconByName('navFirst');
    }
    public function navLast(): IconInterface
    {
        return $this->getIconByName('navLast');
    }
    public function add(): IconInterface
    {
        return $this->getIconByName('add');
    }
    public function view(): IconInterface
    {
        return $this->getIconByName('view');
    }
    public function edit(): IconInterface
    {
        return $this->getIconByName('edit');
    }
    public function delete(): IconInterface
    {
        return $this->getIconByName('delete');
    }
    public function optional(): IconInterface
    {
        return $this->getIconByName('optional');
    }
    public function check(): IconInterface
    {
        return $this->getIconByName('check');
    }
    public function search(): IconInterface
    {
        return $this->getIconByName('search');
    }
    public function account(): IconInterface
    {
        return $this->getIconByName('account');
    }
    public function group(): IconInterface
    {
        return $this->getIconByName('group');
    }
    public function settings(): IconInterface
    {
        return $this->getIconByName('settings');
    }
    public function info(): IconInterface
    {
        return $this->getIconByName('info');
    }
    public function enabled(): IconInterface
    {
        return $this->getIconByName('enabled');
    }
    public function remove(): IconInterface
    {
        return $this->getIconByName('remove');
    }
}
