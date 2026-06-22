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

namespace SP\Domain\Core;


/**
 * Class Language for handling the language used by the application
 *
 * @package SP
 */
interface LanguageInterface
{
    /**
     * Return the available languages
     */
    public static function getAvailableLanguages(): array;

    /**
     * Set the language to use
     *
     * @param bool $force Force language detection for session logins
     */
    public function setLanguage(bool $force = false): void;

    /**
     * Set the gettext locales
     */
    public function setLocales(string $lang): void;

    /**
     * Set the global language for translations
     */
    public function setAppLocales(): void;

    /**
     * Reset the global language for translations
     */
    public function unsetAppLocales(): void;
}
