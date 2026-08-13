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

namespace SP\Domain\Auth\Providers\Ldap {

    /**
     * Holds the DNS_NS records the shadowed dns_get_record() below should hand back to
     * LdapMsAds::pickServer(). Defaults to `false` (i.e. "the zone query found nothing"), which is
     * also what a real query for a non-existent/unreachable zone returns, so tests that don't
     * touch this run exactly like they did before the shadow existed.
     */
    final class DnsRecordsState
    {
        /** @var array<int, array<string, mixed>>|false */
        public static array|false $records = false;
    }

    /**
     * Shadows the global dns_get_record() for code running in the
     * SP\Domain\Auth\Providers\Ldap namespace (i.e. LdapMsAds::pickServer()). PHP resolves
     * unqualified function calls to the current namespace first, so this is picked up instead of
     * the real DNS lookup — which would otherwise make pickServer() depend on whatever DNS
     * infrastructure (or lack of it) the test host happens to have.
     */
    function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
    {
        return DnsRecordsState::$records;
    }
}

namespace SP\Tests\Unit\Domain\Auth\Providers\Ldap {

    use ArrayIterator;
    use EmptyIterator;
    use SP\Domain\Core\Events\Event;
    use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\MockObject\MockObject;
    use SP\Domain\Auth\Ports\LdapActionsService;
    use SP\Domain\Auth\Ports\LdapConnectionHandler;
    use SP\Domain\Auth\Providers\Ldap\DnsRecordsState;
    use SP\Domain\Auth\Providers\Ldap\LdapException;
    use SP\Domain\Auth\Providers\Ldap\LdapMsAds;
    use SP\Domain\Auth\Providers\Ldap\LdapParams;
    use SP\Domain\Auth\Providers\Ldap\LdapResults;
    use SP\Domain\Auth\Providers\Ldap\LdapTypeEnum;
    use SP\Domain\Auth\Providers\Ldap\LdapUtil;
    use SP\Domain\Core\Events\EventDispatcherInterface;
    use SP\Domain\Core\Exceptions\SPException;
    use SP\Tests\Support\UnitaryTestCase;

    /**
     * Class LdapMsAdsTest
     *
     */
    #[Group('unitary')]
    #[AllowMockObjectsWithoutExpectations]
    class LdapMsAdsTest extends UnitaryTestCase
    {

    private LdapConnectionHandler|MockObject $ldapConnection;
    private LdapActionsService|MockObject    $ldapActions;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private LdapMsAds                           $ldap;
    private LdapParams                          $ldapParams;

    public static function groupDataProvider(): array
    {
        return [
            [''],
            ['*'],
            ['cn=TestGroup,dc=groups,dc=syspass,dc=org']
        ];
    }

    /**
     * @throws LdapException
     */
    public function testConnect()
    {
        $user = self::$faker->userName();
        $password = self::$faker->password();

        $this->ldapConnection->expects(self::once())->method('connect')->with($this->ldapParams, $user, $password);

        $this->ldap->connect($user, $password);
    }

    /**
     * @throws LdapException
     */
    public function testConnectWithNull()
    {
        $this->ldapConnection->expects(self::once())->method('connect')->with($this->ldapParams, null, null);

        $this->ldap->connect();
    }

    /**
     * @throws LdapException
     */
    #[DataProvider('groupDataProvider')]
    public function testIsUserInGroup(string $group)
    {
        $this->ldapParams->setGroup($group);

        $userDn = 'cn=TestUser,dc=syspass,dc=org';
        $userLogin = self::$faker->userName();
        $groupsDn = [
            'cn=TestGroup,dc=groups,dc=syspass,dc=org'
        ];

        $this->eventDispatcher
            ->expects(self::once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.group'));

        $out = $this->ldap->isUserInGroup($userDn, $userLogin, $groupsDn);

        self::assertTrue($out);
    }

    /**
     * @throws LdapException
     */
    public function testIsUserInGroupWithSearchGroupDn()
    {
        $this->ldapParams->setGroup('TestGroup');

        $userDn = 'cn=TestUser,dc=syspass,dc=org';
        $userLogin = self::$faker->userName();
        $groupsDn = [
            'cn=TestGroup,dc=groups,dc=syspass,dc=org'
        ];

        $this->ldapActions->expects(self::once())
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturn($groupsDn);

        $this->eventDispatcher
            ->expects(self::once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.group'));

        $out = $this->ldap->isUserInGroup($userDn, $userLogin, $groupsDn);

        self::assertTrue($out);
    }

    /**
     * @throws LdapException
     */
    public function testIsUserInGroupWithCheckFilter()
    {
        $this->ldapParams->setGroup('TestGroup');

        $userDn = 'cn=TestUser,dc=syspass,dc=org';
        $userLogin = self::$faker->userName();
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';

        $this->ldapActions->expects(self::exactly(3))
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturnOnConsecutiveCalls([], [], [$groupDn]);

        $groupsFilter = '(|(memberOf=cn=TestGroup,dc=groups,dc=syspass,dc=org)(groupMembership=cn=TestGroup,dc=groups,dc=syspass,dc=org)(memberof:1.2.840.113556.1.4.1941:=cn=TestGroup,dc=groups,dc=syspass,dc=org))';

        $this->ldapActions
            ->expects(self::once())
            ->method('getObjects')
            ->with($groupsFilter, ['dn'], $userDn)
            ->willReturn(new LdapResults(new ArrayIterator([1])));

        $this->eventDispatcher
            ->expects(self::once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.group'));

        $out = $this->ldap->isUserInGroup($userDn, $userLogin, [$groupDn]);

        self::assertTrue($out);
    }

    /**
     * @throws LdapException
     */
    public function testIsUserInGroupWithCheckFilterAndZeroResults()
    {
        $this->ldapParams->setGroup('TestGroup');

        $userDn = 'cn=TestUser,dc=syspass,dc=org';
        $userLogin = self::$faker->userName();
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';

        $this->ldapActions->expects(self::exactly(3))
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturnOnConsecutiveCalls([], [], [$groupDn]);

        $groupsFilter = '(|(memberOf=cn=TestGroup,dc=groups,dc=syspass,dc=org)(groupMembership=cn=TestGroup,dc=groups,dc=syspass,dc=org)(memberof:1.2.840.113556.1.4.1941:=cn=TestGroup,dc=groups,dc=syspass,dc=org))';

        $this->ldapActions
            ->expects(self::once())
            ->method('getObjects')
            ->with($groupsFilter, ['dn'], $userDn)
            ->willReturn(new LdapResults(new EmptyIterator()));

        $this->eventDispatcher
            ->expects(self::once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.group'));

        $out = $this->ldap->isUserInGroup($userDn, $userLogin, [$groupDn]);

        self::assertFalse($out);
    }

    /**
     * @throws SPException
     */
    public function testGetGroupMembershipIndirectFilter()
    {
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';
        $this->ldapParams->setGroup('TestGroup');

        $this->ldapActions->expects(self::once())
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturn([$groupDn]);

        $out = $this->ldap->getGroupMembershipIndirectFilter();

        $expected = '(&(|'
                    . LdapUtil::getAttributesForFilter(LdapMsAds::DEFAULT_FILTER_GROUP_ATTRIBUTES, $groupDn)
                    . ')'
                    . LdapMsAds::DEFAULT_FILTER_USER_OBJECT
                    . ')';

        self::assertEquals($expected, $out);
    }

    /**
     * @throws SPException
     */
    public function testGetGroupMembershipIndirectFilterWithEmptyGroup()
    {
        $this->ldapActions->expects(self::never())
                          ->method('searchGroupsDn');

        $out = $this->ldap->getGroupMembershipIndirectFilter();

        self::assertEquals(LdapMsAds::DEFAULT_FILTER_USER_OBJECT, $out);
    }

    /**
     * @throws SPException
     */
    public function testGetGroupMembershipIndirectFilterWithAttributes()
    {
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';
        $this->ldapParams->setGroup('TestGroup');
        $this->ldapParams->setFilterGroupAttributes(['testAttribute']);

        $this->ldapActions->expects(self::once())
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturn([$groupDn]);

        $out = $this->ldap->getGroupMembershipIndirectFilter();

        $expected = '(&(|'
                    . LdapUtil::getAttributesForFilter(['testAttribute'], $groupDn)
                    . ')'
                    . LdapMsAds::DEFAULT_FILTER_USER_OBJECT
                    . ')';

        self::assertEquals($expected, $out);
    }

    public function testGetUserDnFilter()
    {
        $user = self::$faker->userName();

        $out = $this->ldap->getUserDnFilter($user);

        $expected = '(&(|'
                    . LdapUtil::getAttributesForFilter(LdapMsAds::DEFAULT_FILTER_USER_ATTRIBUTES, $user)
                    . ')'
                    . LdapMsAds::DEFAULT_FILTER_USER_OBJECT
                    . ')';

        self::assertEquals($expected, $out);
    }

    public function testGetUserDnFilterWithAttributes()
    {
        $this->ldapParams->setFilterUserAttributes(['memberOf']);
        $user = self::$faker->userName();

        $out = $this->ldap->getUserDnFilter($user);

        $expected = '(&(|'
                    . LdapUtil::getAttributesForFilter(['memberOf'], $user)
                    . ')'
                    . LdapMsAds::DEFAULT_FILTER_USER_OBJECT
                    . ')';

        self::assertEquals($expected, $out);
    }

    /**
     * A configured "filter user object" overrides the built-in AD person/user filter — used to
     * narrow (or change) who counts as a matchable user account. testGetUserDnFilter() above only
     * exercises the built-in default; this pins that an administrator's override actually reaches
     * the built DN filter.
     */
    public function testGetUserDnFilterWithCustomUserObjectFilter()
    {
        $this->ldapParams->setFilterUserObject('(objectClass=inetOrgPerson)');
        $user = self::$faker->userName();

        $out = $this->ldap->getUserDnFilter($user);

        $expected = '(&(|'
                    . LdapUtil::getAttributesForFilter(LdapMsAds::DEFAULT_FILTER_USER_ATTRIBUTES, $user)
                    . ')'
                    . '(objectClass=inetOrgPerson)'
                    . ')';

        self::assertEquals($expected, $out);
    }

    public function testGetGroupObjectFilter()
    {
        $out = $this->ldap->getGroupObjectFilter();

        self::assertEquals(LdapMsAds::DEFAULT_FILTER_GROUP_OBJECT, $out);
    }

    public function testGetGroupObjectFilterWithFilter()
    {
        $this->ldapParams->setFilterGroupObject('test');

        $out = $this->ldap->getGroupObjectFilter();

        self::assertEquals('test', $out);
    }

    public function testGetServer()
    {
        self::assertEquals($this->ldapParams->getServer(), $this->ldap->getServer());
    }

    /**
     * @throws LdapException
     */
    public function testGetGroupMembershipDirectFilter()
    {
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';
        $this->ldapParams->setGroup('TestGroup');

        $this->ldapActions->expects(self::once())
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturn([$groupDn]);

        $out = $this->ldap->getGroupMembershipDirectFilter();

        $expected = '(|'
                    . LdapUtil::getAttributesForFilter(LdapMsAds::DEFAULT_FILTER_GROUP_ATTRIBUTES, $groupDn)
                    . ')';

        self::assertEquals($expected, $out);
    }

    /**
     * @throws LdapException
     */
    public function testGetGroupMembershipDirectFilterWithAttributes()
    {
        $groupDn = 'cn=TestGroup,dc=groups,dc=syspass,dc=org';
        $this->ldapParams->setGroup('TestGroup');
        $this->ldapParams->setFilterGroupAttributes(['testAttribute']);

        $this->ldapActions->expects(self::once())
                          ->method('searchGroupsDn')
                          ->with($this->ldap->getGroupObjectFilter())
                          ->willReturn([$groupDn]);

        $out = $this->ldap->getGroupMembershipDirectFilter();

        $expected = '(|'
                    . LdapUtil::getAttributesForFilter(['testAttribute'], $groupDn)
                    . ')';

        self::assertEquals($expected, $out);
    }

    /**
     * pickServer() recognises a bare IP address and uses it as-is, skipping the DNS site-discovery
     * query entirely — this is the fixed-server configuration case, and it must never attempt a
     * network lookup for something that is already an address.
     */
    public function testPickServerReturnsAnIpAddressWithoutQueryingDns()
    {
        $ip = '10.20.30.40';

        $ldapParams = new LdapParams($ip, LdapTypeEnum::ADS, self::$faker->userName(), self::$faker->password());
        $ldap = new LdapMsAds($this->ldapConnection, $this->ldapActions, $ldapParams, $this->eventDispatcher);

        self::assertSame($ip, $ldap->getServer());
    }

    /**
     * A configured server with no dot (e.g. a bare hostname) can't be turned into a "_msdcs.<zone>"
     * DNS query, so pickServer() must fall back to using it directly instead of building a
     * malformed query.
     */
    public function testPickServerReturnsTheServerDirectlyWhenItHasNoDomainPart()
    {
        $server = 'adserver';

        $ldapParams = new LdapParams(
            $server,
            LdapTypeEnum::ADS,
            self::$faker->userName(),
            self::$faker->password()
        );
        $ldap = new LdapMsAds($this->ldapConnection, $this->ldapActions, $ldapParams, $this->eventDispatcher);

        self::assertSame($server, $ldap->getServer());
    }

    /**
     * When the "_msdcs.<zone>" query does return NS records, pickServer() must use one of the
     * targets the query actually returned, not the originally configured server — that's the
     * entire point of the site-discovery query (Active Directory advertises the nearest domain
     * controller this way).
     */
    public function testPickServerUsesADnsReturnedTargetWhenTheZoneQueryFindsRecords()
    {
        $resolvedServer = 'dc1.ad.example.com';
        DnsRecordsState::$records = [
            ['target' => $resolvedServer],
        ];

        try {
            $ldapParams = new LdapParams(
                'ad.example.com',
                LdapTypeEnum::ADS,
                self::$faker->userName(),
                self::$faker->password()
            );
            $ldap = new LdapMsAds($this->ldapConnection, $this->ldapActions, $ldapParams, $this->eventDispatcher);

            self::assertSame($resolvedServer, $ldap->getServer());
        } finally {
            DnsRecordsState::$records = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Make sure no earlier test left a DNS response behind for tests that don't set one.
        DnsRecordsState::$records = false;

        $this->ldapConnection = $this->createMock(LdapConnectionHandler::class);
        $this->ldapActions = $this->createMock(LdapActionsService::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->ldapParams = new LdapParams(
            self::$faker->domainName(),
            LdapTypeEnum::ADS,
            self::$faker->userName(),
            self::$faker->password()
        );

        $this->ldap = new LdapMsAds(
            $this->ldapConnection,
            $this->ldapActions,
            $this->ldapParams,
            $this->eventDispatcher
        );
    }
}
}
