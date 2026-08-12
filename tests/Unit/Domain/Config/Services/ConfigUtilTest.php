<?php
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

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Config\Services;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Config\Services\ConfigUtil;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class ConfigUtilTest
 *
 * Both adapters turn what an administrator typed into a configured list, dropping what cannot be
 * used. What they drop is the point: a malformed address left in the list would make every
 * notification attempt fail, and an arbitrary string among the event names would be matched
 * against every event.
 */
#[Group('unitary')]
class ConfigUtilTest extends UnitaryTestCase
{
    public function testAListOfAddressesKeepsOnlyTheValidOnes(): void
    {
        $addresses = ConfigUtil::mailAddressesAdapter(
            'someone@example.invalid,not-an-address,other@example.invalid'
        );

        self::assertCount(2, $addresses);
        self::assertContains('someone@example.invalid', $addresses);
        self::assertContains('other@example.invalid', $addresses);
        self::assertNotContains('not-an-address', $addresses);
    }

    public function testAnEmptyAddressListIsEmpty(): void
    {
        self::assertSame([], ConfigUtil::mailAddressesAdapter(''));
    }

    /**
     * A single address needs no separator.
     */
    public function testASingleAddressIsKept(): void
    {
        self::assertSame(['someone@example.invalid'], ConfigUtil::mailAddressesAdapter('someone@example.invalid'));
    }

    /**
     * Event names are dotted identifiers; anything else is dropped rather than being registered
     * as a name that could match unexpectedly.
     */
    public function testEventNamesAreKeptOnlyWhenTheyLookLikeEventNames(): void
    {
        $events = ConfigUtil::eventsAdapter(
            ['create.account', 'edit.user.password', 'not a name', '123', '', 'login']
        );

        self::assertContains('create.account', $events);
        self::assertContains('edit.user.password', $events);
        self::assertContains('login', $events);
        self::assertNotContains('not a name', $events);
        self::assertNotContains('123', $events);
        self::assertNotContains('', $events);
    }

    public function testAnEmptyEventListIsEmpty(): void
    {
        self::assertSame([], ConfigUtil::eventsAdapter([]));
    }
}
