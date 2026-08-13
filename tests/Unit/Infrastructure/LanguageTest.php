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

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Infrastructure\Language;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\User;
use SP\Domain\User\Models\UserPreferences;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class LanguageTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LanguageTest extends UnitaryTestCase
{

    private ConfigDataInterface|MockObject $configData;
    private RequestService|MockObject $request;
    private Language                       $language;

    public function testSetLocales()
    {
        $locale = 'es_ES';

        $this->language->setLocales($locale);

        $this->assertEquals($locale . '.utf8', Language::$localeStatus);
        $this->assertEquals($locale . '.utf8', getenv('LANG'));
        $this->assertEquals($locale . '.utf8', getenv('LANGUAGE'));
    }

    public function testSetLanguage()
    {
        $locale = 'es_ES';

        $this->context->setLocale($locale);

        $this->language->setLanguage();

        $this->assertEquals($locale . '.utf8', Language::$localeStatus);
        $this->assertEquals($locale . '.utf8', getenv('LANG'));
        $this->assertEquals($locale . '.utf8', getenv('LANGUAGE'));
    }

    /**
     * @throws SPException
     */
    public function testSetLanguageForceWithUserLanguage()
    {
        $locale = 'es_ES';

        $this->context->setLocale($locale);
        $this->configData
            ->method('isInstalled')
            ->willReturn(true);
        $this->configData
            ->expects(self::once())
            ->method('getSiteLang')
            ->willReturn(self::$faker->locale());

        $user = (new User(['id' => self::$faker->randomNumber(2)]))
            ->dehydrate(new UserPreferences(['lang' => $locale]));

        $this->context->setUserData(UserDto::fromModel($user));

        $this->language->setLanguage(true);

        $this->assertEquals($locale . '.utf8', Language::$localeStatus);
        $this->assertEquals($locale . '.utf8', getenv('LANG'));
        $this->assertEquals($locale . '.utf8', getenv('LANGUAGE'));
        $this->assertEquals($locale, $this->context->getLocale());
    }

    public function testSetLanguageForceWithAppLanguage()
    {
        $locale = 'es_ES';
        $appLocale = 'en_US';

        $this->context->setLocale($locale);

        $this->context->setUserData(new UserDto());

        $this->configData
            ->method('isInstalled')
            ->willReturn(true);
        $this->configData
            ->expects(self::once())
            ->method('getSiteLang')
            ->willReturn($appLocale);

        $this->language->setLanguage(true);

        $this->assertEquals($appLocale . '.utf8', Language::$localeStatus);
        $this->assertEquals($appLocale . '.utf8', getenv('LANG'));
        $this->assertEquals($appLocale . '.utf8', getenv('LANGUAGE'));
        $this->assertEquals($appLocale, $this->context->getLocale());
    }

    public function testSetLanguageForceWithDefaultSiteLang()
    {
        $locale = 'es_ES';
        $defaultLang = 'en_US';

        $this->context->setLocale($locale);

        $this->context->setUserData(new UserDto());

        $this->configData
            ->method('isInstalled')
            ->willReturn(true);
        $this->configData
            ->expects(self::once())
            ->method('getSiteLang')
            ->willReturn($defaultLang);

        $this->language->setLanguage(true);

        $this->assertEquals($defaultLang . '.utf8', Language::$localeStatus);
        $this->assertEquals($defaultLang . '.utf8', getenv('LANG'));
        $this->assertEquals($defaultLang . '.utf8', getenv('LANGUAGE'));
        $this->assertEquals($defaultLang, $this->context->getLocale());
    }

    public function testGetAvailableLanguages()
    {
        $out = Language::getAvailableLanguages();

        $this->assertCount(14, $out);
    }

    public function testSetAppLocales()
    {
        $locale = 'es_ES';
        $appLocale = 'en_US';

        $this->context->setLocale($locale);

        $this->configData
            ->expects(self::once())
            ->method('getSiteLang')
            ->willReturn($appLocale);

        $this->language->setAppLocales();

        $this->assertEquals($appLocale . '.utf8', Language::$localeStatus);
        $this->assertEquals($appLocale . '.utf8', getenv('LANG'));
        $this->assertEquals($appLocale . '.utf8', getenv('LANGUAGE'));
    }

    public function testUnsetAppLocales()
    {
        $locale = 'es_ES';

        $this->context->setLocale($locale);

        $this->language->unsetAppLocales();

        $this->assertEquals($locale . '.utf8', Language::$localeStatus);
        $this->assertEquals($locale . '.utf8', getenv('LANG'));
        $this->assertEquals($locale . '.utf8', getenv('LANGUAGE'));
    }

    /**
     * A first-time visitor has no saved language preference, so the UI language
     * is picked from the browser's Accept-Language header. Getting this
     * resolution wrong means visitors either see the wrong language on first
     * load, or everyone silently falls back to English even when their exact
     * or a closely related locale is supported.
     */
    #[TestWith(['', 'en_US'])]
    #[TestWith(['de-DE,de;q=0.9,en;q=0.8', 'de_DE'])]
    #[TestWith(['pt-PT,pt;q=0.8', 'pt_BR'])]
    #[TestWith(['zz-ZZ,zz;q=0.5', 'en_US'])]
    public function testResolveLanguage(string $acceptLanguageHeader, string $expected)
    {
        $this->assertEquals($expected, Language::resolveLanguage($acceptLanguageHeader));
    }

    /**
     * Before installation there is no saved site language, so the browser's
     * Accept-Language header must drive the language of the installer pages.
     * Existing coverage only exercised the "already installed" branch of
     * getGlobalLang(); if the not-installed branch regressed and always fell
     * through to the (empty) configured site language, the installer would be
     * stuck showing English regardless of the visitor's browser.
     */
    public function testSetLanguageForceWhenNotInstalledUsesBrowserLanguage()
    {
        $locale = 'en_US';
        $browserLocale = 'es_ES';

        $this->context->setLocale($locale);
        $this->context->setUserData(new UserDto());

        $this->configData
            ->method('isInstalled')
            ->willReturn(false);
        $this->request
            ->expects(self::once())
            ->method('getHeader')
            ->with('Accept-Language')
            ->willReturn('es-ES,es;q=0.9');

        $this->language->setLanguage(true);

        $this->assertEquals($browserLocale, Language::$globalLang);
        $this->assertEquals($browserLocale, $this->context->getLocale());
        $this->assertEquals($browserLocale . '.utf8', Language::$localeStatus);
        $this->assertEquals($browserLocale . '.utf8', getenv('LANG'));
        $this->assertEquals($browserLocale . '.utf8', getenv('LANGUAGE'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configData = $this->createMock(ConfigDataInterface::class);
        $this->request = $this->createMock(RequestService::class);

        $this->language = new Language($this->context, $this->configData, $this->request, RESOURCE_PATH);
    }
}
