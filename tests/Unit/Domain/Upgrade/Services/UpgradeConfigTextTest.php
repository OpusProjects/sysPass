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

namespace SP\Tests\Unit\Domain\Upgrade\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Upgrade\Services\UpgradeConfigText;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The configuration's half of the migration for text that was stored escaped.
 *
 * `config.xml` is a file rather than a table, so the SQL that repairs the database rows cannot
 * reach it — and it holds the setting where the escaping did more than look wrong. An LDAP filter
 * is written `(&(objectClass=user))`, was stored `(&amp;(objectClass=user))`, and was handed to
 * the directory in that form, where `&amp;` is not an operator and the search matches nothing.
 */
#[Group('unitary')]
class UpgradeConfigTextTest extends UnitaryTestCase
{
    /**
     * The one with consequences beyond how it reads.
     */
    #[Test]
    public function anLdapFilterIsMadeIntoAFilterAgain(): void
    {
        $configData = new ConfigData();
        $configData->setLdapFilterUserObject('(&amp;(objectClass=user)(memberOf=CN=X))');

        $this->apply($configData);

        self::assertSame('(&(objectClass=user)(memberOf=CN=X))', $configData->getLdapFilterUserObject());
    }

    /**
     * Ordinary settings come back as they were typed.
     */
    #[Test]
    public function textSettingsAreDecoded(): void
    {
        $configData = new ConfigData();
        $configData->setApplicationUrl('https://acme.invalid/?a=1&amp;b=2');
        $configData->setWikiSearchurl('https://wiki.invalid/?q=1&amp;s=2');

        $this->apply($configData);

        self::assertSame('https://acme.invalid/?a=1&b=2', $configData->getApplicationUrl());
        self::assertSame('https://wiki.invalid/?q=1&s=2', $configData->getWikiSearchurl());
    }

    /**
     * A list is walked as well — the allowed MIME types and the mail recipients are both stored
     * that way.
     */
    #[Test]
    public function listsOfTextAreDecodedToo(): void
    {
        $configData = new ConfigData();
        $configData->setFilesAllowedMime(['application/x-a&amp;b', 'text/plain']);

        $this->apply($configData);

        self::assertSame(['application/x-a&b', 'text/plain'], $configData->getFilesAllowedMime());
    }

    /**
     * The ampersand is decoded last, so a value that literally contained an entity when it was
     * typed keeps it. Decoding it first would take `&amp;lt;` to `&lt;` and then to `<`.
     */
    #[Test]
    public function anEntitySomebodyTypedSurvives(): void
    {
        $configData = new ConfigData();
        $configData->setLdapBase('&amp;lt;');

        $this->apply($configData);

        self::assertSame('&lt;', $configData->getLdapBase());
    }

    /**
     * Nothing that is not text is touched, and nothing without an entity in it changes — which is
     * why the hashes and the numbers need no exclusion by name.
     */
    #[Test]
    public function valuesWithNothingToDecodeAreLeftAlone(): void
    {
        $configData = new ConfigData();
        $configData->setSessionTimeout(300);
        $configData->setPasswordSalt('0a1b2c3d4e5f');
        $configData->setLdapBase('an ordinary name');
        $configData->setMaintenance(true);

        $this->apply($configData);

        self::assertSame(300, $configData->getSessionTimeout());
        self::assertSame('0a1b2c3d4e5f', $configData->getPasswordSalt());
        self::assertSame('an ordinary name', $configData->getLdapBase());
        self::assertTrue($configData->isMaintenance());
    }

    private function apply(ConfigData $configData): void
    {
        self::assertTrue((new UpgradeConfigText($this->application))->apply('400.24240101', $configData));
    }
}
