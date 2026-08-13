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

namespace SP\Tests\Unit\Domain\Auth\Providers\Ldap;

use ArrayIterator;
use Laminas\Ldap\Ldap;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Events\Event;
use SP\Domain\Auth\Providers\Ldap\AttributeCollection;
use SP\Domain\Auth\Providers\Ldap\LdapActions;
use SP\Domain\Auth\Providers\Ldap\LdapCodeEnum;
use SP\Domain\Auth\Providers\Ldap\LdapException;
use SP\Domain\Auth\Providers\Ldap\LdapParams;
use SP\Domain\Auth\Providers\Ldap\LdapResults;
use SP\Domain\Auth\Providers\Ldap\LdapTypeEnum;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class LdapActionsTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LdapActionsTest extends UnitaryTestCase
{

    private Ldap|MockObject                     $ldap;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private LdapActions                         $ldapActions;

    /**
     * @throws LdapException
     * @throws Exception
     */
    public function testGetObjects(): void
    {
        $filter = 'test';
        $iterator = new ArrayIterator(range(0, 9));

        $attributes = array_map(fn() => self::$faker->colorName(), range(0, 9));
        $searchBase = self::$faker->colorName();

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       $filter,
                       $searchBase,
                       Ldap::SEARCH_SCOPE_SUB,
                       $attributes,
                   )
            ->willReturn($iterator);

        $out = $this->ldapActions->getObjects($filter, $attributes, $searchBase);

        self::assertEquals(new LdapResults($iterator), $out);
    }

    /**
     * @throws LdapException
     */
    public function testGetObjectsError(): void
    {
        $this->expectGetResultsError();

        $this->ldapActions->getObjects('test');
    }

    /**
     * @return void
     */
    private function expectGetResultsError(): void
    {
        $message = 'test';
        $code = self::$faker->randomNumber();
        $exception = new \Laminas\Ldap\Exception\LdapException(null, $message, $code);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->willThrowException($exception);

        $this->eventDispatcher->expects(self::once())
                              ->method('notify')
                              ->with(new Event('exception', $exception));

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage($message);
        $this->expectExceptionCode($code);
    }

    /**
     * @throws LdapException
     * @throws Exception
     */
    public function testGetAttributes(): void
    {
        $attributes = $this->buildAttributes();
        $iterator = new ArrayIterator([$attributes]);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       'a_filter',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       [],
                   )
            ->willReturn($iterator);

        $out = $this->ldapActions->getAttributes('a_filter');

        $expected = new AttributeCollection([
                                                'dn' => $attributes['dn'],
                                                'group' => array_filter(
                                                    $attributes['memberof'],
                                                    static fn($key) => $key !== 'count',
                                                    ARRAY_FILTER_USE_KEY
                                                ),
                                                'fullname' => $attributes['displayname'],
                                                'name' => $attributes['givenname'],
                                                'sn' => $attributes['sn'],
                                                'mail' => $attributes['mail'],
                                                'expire' => $attributes['lockouttime'],
                                            ]);

        self::assertEquals($expected, $out);
    }

    /**
     * @return array
     */
    private function buildAttributes(): array
    {
        return [
            'dn' => self::$faker->userName(),
            'memberof' => [
                'count' => 3,
                self::$faker->company(),
                self::$faker->company(),
                self::$faker->company(),
            ],
            'displayname' => self::$faker->name(),
            'givenname' => self::$faker->firstName(),
            'sn' => self::$faker->lastName(),
            'mail' => self::$faker->email(),
            'lockouttime' => self::$faker->unixTime(),
        ];
    }

    /**
     * @throws LdapException
     */
    public function testGetAttributesError(): void
    {
        $this->expectGetResultsError();

        $this->ldapActions->getAttributes('test');
    }

    /**
     * A search that matches the LDAP entry (no LdapException) but yields no entry at all — as
     * opposed to a search error — must come back as an empty AttributeCollection so the caller
     * (LdapAuth::getAttributes()) can tell "user not found" apart from "search failed" and fail
     * the login closed either way.
     *
     * @throws LdapException
     * @throws Exception
     */
    public function testGetAttributesReturnsAnEmptyCollectionWhenNothingMatches(): void
    {
        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       'a_filter',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       [],
                   )
            ->willReturn(new ArrayIterator([]));

        $out = $this->ldapActions->getAttributes('a_filter');

        self::assertEquals(new AttributeCollection(), $out);
    }

    /**
     * Directories represent a single-valued attribute as either a bare scalar or as an array with
     * a "count" of 1 (LDAP's native multi-value shape, just with one value). Only the "count > 1"
     * case stores the whole array; this pins that the single-value array shape still yields the
     * plain value, not the wrapper array, so it isn't mistaken for a genuinely multi-valued one.
     *
     * @throws LdapException
     * @throws Exception
     */
    public function testGetAttributesUnwrapsASingleValuedArrayAttribute(): void
    {
        $attributes = $this->buildAttributes();
        $mail = $attributes['mail'];
        $attributes['mail'] = ['count' => 1, 0 => $mail];

        $iterator = new ArrayIterator([$attributes]);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       'a_filter',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       [],
                   )
            ->willReturn($iterator);

        $out = $this->ldapActions->getAttributes('a_filter');

        self::assertSame($mail, $out->get('mail'));
    }

    /**
     * @throws LdapException
     * @throws Exception
     */
    public function testSearchGroupsDn(): void
    {
        $expected = [
            [],
            [
                'dn' => self::$faker->name(),
            ],
        ];
        $filter = 'test';
        $iterator = new ArrayIterator($expected);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       '(&(cn=\2a)test)',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       ['dn'],
                   )
            ->willReturn($iterator);

        $out = $this->ldapActions->searchGroupsDn($filter);

        self::assertEquals($expected[1]['dn'], $out[0]);
    }

    /**
     * A directory entry that isn't shaped as an array (e.g. a stray scalar in the result set)
     * must be dropped rather than crash the mapping — searchGroupsDn() only knows how to pull a
     * "dn" out of an array-shaped entry.
     *
     * @throws LdapException
     * @throws Exception
     */
    public function testSearchGroupsDnIgnoresNonArrayResultEntries(): void
    {
        $groupDn = self::$faker->name();
        $filter = 'test';
        $iterator = new ArrayIterator([
                                          'not-an-array-entry',
                                          ['dn' => $groupDn],
                                      ]);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       '(&(cn=\2a)test)',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       ['dn'],
                   )
            ->willReturn($iterator);

        $out = $this->ldapActions->searchGroupsDn($filter);

        self::assertSame([$groupDn], $out);
    }

    /**
     * When the configured "group" is given as a full DN (starts with "cn="), the search filter
     * must use only the plain CN, not the whole DN — a DN embedded raw into a "(cn=...)" filter
     * term would never match anything on the directory.
     *
     * @throws LdapException
     * @throws Exception
     */
    public function testSearchGroupsDnUsesTheCnWhenGroupIsGivenAsADn(): void
    {
        $ldapActions = new LdapActions($this->ldap, $this->eventDispatcher, null, 'cn=Admins,dc=example,dc=com');

        $groupDn = self::$faker->name();
        $iterator = new ArrayIterator([['dn' => $groupDn]]);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       '(&(cn=Admins)test)',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       ['dn'],
                   )
            ->willReturn($iterator);

        $out = $ldapActions->searchGroupsDn('test');

        self::assertSame([$groupDn], $out);
    }

    /**
     * A configured "group" that isn't a DN (doesn't start with "cn=") is used as-is as the CN —
     * this is the plain "group name" configuration case, as opposed to the full-DN case above.
     *
     * @throws LdapException
     * @throws Exception
     */
    public function testSearchGroupsDnUsesThePlainGroupNameWhenNotGivenAsADn(): void
    {
        $ldapActions = new LdapActions($this->ldap, $this->eventDispatcher, null, 'AdminsGroup');

        $groupDn = self::$faker->name();
        $iterator = new ArrayIterator([['dn' => $groupDn]]);

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       '(&(cn=AdminsGroup)test)',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       ['dn'],
                   )
            ->willReturn($iterator);

        $out = $ldapActions->searchGroupsDn('test');

        self::assertSame([$groupDn], $out);
    }

    /**
     * @throws LdapException
     * @throws Exception
     */
    public function testSearchGroupsDnNoGroups(): void
    {
        $filter = 'test';
        $iterator = new ArrayIterator();

        $this->ldap->expects(self::once())
                   ->method('search')
                   ->with(
                       '(&(cn=\2a)test)',
                       null,
                       Ldap::SEARCH_SCOPE_SUB,
                       ['dn'],
                   )
            ->willReturn($iterator);

        $this->eventDispatcher->expects(self::once())
                              ->method('notify')
                              ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.search.group'));

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage('Error while searching the group RDN');
        $this->expectExceptionCode(LdapCodeEnum::NO_SUCH_OBJECT->value);

        $this->ldapActions->searchGroupsDn($filter);
    }

    /**
     * @throws LdapException
     */
    public function testSearchGroupsDnError(): void
    {
        $this->expectGetResultsError();

        $this->ldapActions->searchGroupsDn('test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ldap = $this->createMock(Ldap::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $ldapParams =
            new LdapParams(self::$faker->domainName(), LdapTypeEnum::STD, self::$faker->userName(), self::$faker->password());

        $this->ldapActions = new LdapActions($this->ldap, $this->eventDispatcher);
    }

}
