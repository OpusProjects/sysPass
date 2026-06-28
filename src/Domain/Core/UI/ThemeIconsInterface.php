<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\Core\UI;

use SP\Domain\Core\Context\Context;
use SP\Domain\Core\UI\IconInterface;
use SP\Infrastructure\File\FileCache;

interface ThemeIconsInterface
{
    public static function loadIcons(
        Context $context,
        FileCache             $cache,
        ThemeContextInterface $themeContext
    ): ThemeIconsInterface;

    public function getIconByName(string $name): IconInterface;

    public function addIcon(string $alias, IconInterface $icon): void;

    public function warning(): IconInterface;

    public function download(): IconInterface;

    public function clear(): IconInterface;

    public function play(): IconInterface;

    public function help(): IconInterface;

    public function publicLink(): IconInterface;

    public function back(): IconInterface;

    public function restore(): IconInterface;

    public function save(): IconInterface;

    public function up(): IconInterface;

    public function down(): IconInterface;

    public function viewPass(): IconInterface;

    public function copy(): IconInterface;

    public function clipboard(): IconInterface;

    public function email(): IconInterface;

    public function refresh(): IconInterface;

    public function editPass(): IconInterface;

    public function appAdmin(): IconInterface;

    public function accAdmin(): IconInterface;

    public function ldapUser(): IconInterface;

    public function disabled(): IconInterface;

    public function navPrev(): IconInterface;

    public function navNext(): IconInterface;

    public function navFirst(): IconInterface;

    public function navLast(): IconInterface;

    public function add(): IconInterface;

    public function view(): IconInterface;

    public function edit(): IconInterface;

    public function delete(): IconInterface;

    public function optional(): IconInterface;

    public function check(): IconInterface;

    public function search(): IconInterface;

    public function account(): IconInterface;

    public function group(): IconInterface;

    public function settings(): IconInterface;

    public function info(): IconInterface;

    public function enabled(): IconInterface;

    public function remove(): IconInterface;
}
