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

use Laminas\Ldap\Ldap;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Exceptions\ContextException;
use SP\Infrastructure\Events\Event;
use SP\Domain\Auth\Providers\Ldap\LdapConnection;
use SP\Domain\Auth\Providers\Ldap\LdapException;
use SP\Domain\Auth\Providers\Ldap\LdapParams;
use SP\Domain\Auth\Providers\Ldap\LdapTypeEnum;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

use function PHPUnit\Framework\once;

/**
 * Class LdapConnectionTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LdapConnectionTest extends UnitaryTestCase
{
    private LdapConnection                      $ldapConnection;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private Ldap|MockObject                     $ldap;
    private LdapParams                          $ldapParams;

    /**
     * @throws LdapException
     */
    public function testCheckConnection(): void
    {
        $this->ldap
            ->expects(self::once())
            ->method('bind')
            ->with($this->ldapParams->getBindDn(), $this->ldapParams->getBindPass());

        $this->eventDispatcher
            ->expects(once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.connection'));

        $this->ldapConnection->connect($this->ldapParams);
    }

    /**
     * A provided-but-empty user password must be passed verbatim, never replaced
     * with the service-account bind password (which would let an empty password
     * authenticate a user).
     *
     * @throws LdapException
     */
    public function testConnectWithEmptyUserPasswordDoesNotUseServicePassword(): void
    {
        $userDn = self::$faker->userName();

        $this->ldap
            ->expects(self::once())
            ->method('bind')
            ->with($userDn, '');

        $this->eventDispatcher
            ->expects(once())
            ->method('notify')
            ->with(self::callback(fn(Event $e) => $e->getName() === 'ldap.check.connection'));

        $this->ldapConnection->connect($this->ldapParams, $userDn, '');
    }

    /**
     * @throws LdapException
     */
    public function testCheckConnectionError(): void
    {
        $this->expectConnectError();

        $this->ldapConnection->connect($this->ldapParams);
    }

    /**
     * @return void
     */
    private function expectConnectError(): void
    {
        $this->ldap
            ->expects(self::once())
            ->method('bind')
            ->with($this->ldapParams->getBindDn(), $this->ldapParams->getBindPass())
            ->willThrowException(new \Laminas\Ldap\Exception\LdapException());

        $this->eventDispatcher
            ->expects(self::exactly(2))
            ->method('notify')
            ->with(self::callback(fn(Event $e) => in_array($e->getName(), ['exception', 'ldap.bind'])));

        $this->ldap
            ->expects(self::exactly(2))
            ->method('getLastError')
            ->willReturn('error');

        $errorCode = self::$faker->randomNumber();

        $this->ldap
            ->expects(self::once())
            ->method('getLastErrorCode')
            ->willReturn($errorCode);

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage('LDAP connection error');
        $this->expectExceptionCode($errorCode);
    }

    /**
     * @throws LdapException
     */
    public function testConnect(): void
    {
        $this->ldap
            ->expects(self::once())
            ->method('bind')
            ->with($this->ldapParams->getBindDn(), $this->ldapParams->getBindPass());

        $this->ldapConnection->connect($this->ldapParams);
    }

    /**
     * @throws LdapException
     */
    public function testConnectError(): void
    {
        $this->expectConnectError();

        $this->ldapConnection->connect($this->ldapParams);
    }

    /**
     * @throws ContextException
     * @throws Exception
     * @throws LdapException
     * @throws SPException
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ldapParams = new LdapParams(
            self::$faker->domainName(),
            LdapTypeEnum::STD,
            'cn=test,dc=example,dc=com',
            self::$faker->password()
        );
        $this->ldapParams->setPort(10389);
        $this->ldapParams->setGroup('cn=Test Group,ou=Groups,dc=example,dc=con');
        $this->ldapParams->setSearchBase('dc=example,dc=com');
        $this->ldapParams->setTlsEnabled(true);

        $this->ldap = $this->createMock(Ldap::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->ldapConnection =
            new LdapConnection($this->ldap, $this->eventDispatcher, true);
    }

}
