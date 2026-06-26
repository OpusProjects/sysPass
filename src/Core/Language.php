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

namespace SP\Core;

use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\LanguageInterface;
use SP\Domain\Http\Ports\RequestService;

use function SP\logger;

/**
 * Class Language for handling the language used by the application
 *
 * @package SP
 */
final class Language implements LanguageInterface
{
    /**
     * User language
     */
    public static string $userLang = '';
    /**
     * Global application language
     */
    public static string $globalLang = '';
    /**
     * Locale status. false if it does not exist
     *
     * @var string|false
     */
    public static string|false $localeStatus;
    /**
     * Whether it has been set to the application's locales
     */
    protected static bool $appSet = false;
    /**
     *  Available languages
     */
    private static array $langs = [
        'es_ES' => 'Español',
        'ca_ES' => 'Catalá',
        'en_US' => 'English',
        'de_DE' => 'Deutsch',
        'hu_HU' => 'Magyar',
        'fr_FR' => 'Français',
        'pl_PL' => 'Polski',
        'ru_RU' => 'русский',
        'nl_NL' => 'Nederlands',
        'pt_BR' => 'Português',
        'it_IT' => 'Italiano',
        'da' => 'Dansk',
        'fo' => 'Føroyskt mál',
        'ja_JP' => '日本語',
    ];

    public function __construct(
        private readonly Context        $context,
        private readonly ConfigDataInterface $configData,
        private readonly RequestService $request,
        private readonly string         $localesPath
    ) {
        ksort(self::$langs);
    }

    /**
     * Return the available languages
     */
    public static function getAvailableLanguages(): array
    {
        return self::$langs;
    }

    /**
     * Set the language to use
     *
     * @param bool $force Force language detection on login
     */
    public function setLanguage(bool $force = false): void
    {
        $lang = $this->context->getLocale();

        if (empty($lang) || $force === true) {
            self::$userLang = $this->getUserLang();
            self::$globalLang = $this->getGlobalLang();

            $lang = self::$userLang ?: self::$globalLang;

            $this->context->setLocale($lang);
        }

        $this->setLocales($lang);
    }

    /**
     * Returns the user's language
     */
    private function getUserLang(): string
    {
        $userDto = $this->context->getUserData();

        return ($userDto->id > 0)
            ? ($userDto->preferences?->getLang() ?? '')
            : '';
    }

    /**
     * Sets the application language.
     * This function sets the language according to what is defined in the configuration or in the browser.
     */
    private function getGlobalLang(): string
    {
        return $this->configData->getSiteLang() ?: $this->getBrowserLang();
    }

    /**
     * Return the language accepted by the browser
     */
    private function getBrowserLang(): string
    {
        $lang = $this->request->getHeader('Accept-Language');

        return $lang !== ''
            ? str_replace('-', '_', substr($lang, 0, 5))
            : 'en_US';
    }

    /**
     * Set the gettext locales
     */
    public function setLocales(string $lang): void
    {
        $lang .= '.utf8';

        self::$localeStatus = setlocale(LC_MESSAGES, $lang);

        putenv('LANG=' . $lang);
        putenv('LANGUAGE=' . $lang);

        $locale = setlocale(LC_ALL, $lang);

        if ($locale === false) {
            logger('Could not set locale to ' . $lang, 'ERROR');
            logger('Domain path: ' . $this->localesPath);
        } else {
            logger('Locale set to: ' . $locale);
        }

        bindtextdomain('messages', $this->localesPath);
        textdomain('messages');
        bind_textdomain_codeset('messages', 'UTF-8');
    }

    /**
     * Set the global language for translations
     */
    public function setAppLocales(): void
    {
        if (!$this->context->isInitialized()) {
            return;
        }

        $siteLang = $this->configData->getSiteLang();

        if ($siteLang !== $this->context->getLocale()) {
            $this->setLocales($siteLang);

            self::$appSet = true;
        }
    }

    /**
     * Reset the global language for translations
     */
    public function unsetAppLocales(): void
    {
        if (self::$appSet === true) {
            $locale = $this->context->getLocale();

            if ($locale !== null) {
                $this->setLocales($locale);
            }

            self::$appSet = false;
        }
    }
}
