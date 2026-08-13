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

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use SP\Domain\Auth\Dtos\UserLoginDto;
use SP\Domain\Auth\Ports\LdapActionsService;
use SP\Domain\Auth\Ports\LdapAuthService;
use SP\Domain\Auth\Ports\LdapService;
use SP\Domain\Auth\Providers\Ldap\AttributeCollection;
use SP\Domain\Auth\Providers\Ldap\LdapAuth;
use SP\Domain\Auth\Providers\Ldap\LdapCodeEnum;
use SP\Domain\Auth\Providers\Ldap\LdapException;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Events\Event;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class LdapAuthTest
 *
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LdapAuthTest extends UnitaryTestCase
{

    private LdapService|MockObject $ldap;
    private EventDispatcherInterface|MockObject $eventDispatcher;
    private ConfigDataInterface|MockObject      $configData;
    private LdapAuth                            $ldapAuth;

    /**
     * @throws Exception
     */
    public function testAuthenticate()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);

        $connectCounter = new InvokedCount(2);

        $this->ldap
            ->expects($connectCounter)
            ->method('connect')
            ->with(
                new Callback(static fn($user) => match ($connectCounter->numberOfInvocations()) {
                    1 => $user === null,
                    2 => !empty($user),
                    default => false
                }),
                new Callback(static fn($pass) => match ($connectCounter->numberOfInvocations()) {
                    1 => $pass === null,
                    2 => !empty($pass),
                    default => false
                })
            );

        $filter = 'test';

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $attributes = $this->buildAttributes();
        $attributes->set('expire', 0);

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->with($attributes->get('dn'), $userLoginData->getLoginUser(), $attributes->get('group'))
            ->willReturn(true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertTrue($out->isOk());
    }

    /**
     * @return AttributeCollection
     */
    private function buildAttributes(): AttributeCollection
    {
        return new AttributeCollection([
                                           'dn' => self::$faker->userName(),
                                           'group' => [
                                               self::$faker->company(),
                                               self::$faker->company(),
                                               self::$faker->company(),
                                           ],
                                           'fullname' => self::$faker->name(),
                                           'name' => self::$faker->firstName(),
                                           'sn' => self::$faker->lastName(),
                                           'mail' => self::$faker->email(),
                                           'expire' => self::$faker->unixTime(),
                                       ]);
    }

    /**
     * @throws Exception
     */
    public function testAuthenticateWithExpireFail()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);

        $this->ldap
            ->expects(self::once())
            ->method('connect')
            ->with(null, null);

        $filter = 'test';

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $attributes = $this->buildAttributes();

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->with($attributes->get('dn'), $userLoginData->getLoginUser(), $attributes->get('group'))
            ->willReturn(true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertFalse($out->isOk());
    }

    /**
     * @throws Exception
     */
    public function testAuthenticateWithGroupFail()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);

        $this->ldap
            ->expects(self::once())
            ->method('connect')
            ->with(null, null);

        $filter = 'test';

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $attributes = $this->buildAttributes();

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->with($attributes->get('dn'), $userLoginData->getLoginUser(), $attributes->get('group'))
            ->willReturn(false);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertFalse($out->isOk());
    }

    /**
     * @throws Exception
     */
    public function testAuthenticateFailConnect()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);

        $this->ldap
            ->expects(self::once())
            ->method('connect')
            ->willThrowException(new LdapException('Exception', SPException::ERROR, null, 1));

        $filter = 'test';

        $this->ldap
            ->expects(self::never())
            ->method('getUserDnFilter');

        $this->ldap
            ->expects(self::never())
            ->method('actions');

        $ldapActions
            ->expects(self::never())
            ->method('getAttributes');

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertFalse($out->isOk());
        self::assertEquals(1, $out->getStatusCode());
    }

    public function testIsAuthGrantedFalseWhenDatabaseEnabled()
    {
        $this->configData
            ->expects(self::once())
            ->method('isLdapDatabaseEnabled')
            ->willReturn(true);

        self::assertFalse($this->ldapAuth->isAuthGranted());
    }

    public function testIsAuthGrantedTrueWhenDatabaseDisabled()
    {
        $this->configData
            ->expects(self::once())
            ->method('isLdapDatabaseEnabled')
            ->willReturn(false);

        self::assertTrue($this->ldapAuth->isAuthGranted());
    }

    /**
     * A directory search that comes back with nothing is the "user doesn't exist" case. It must
     * fail closed (never fall through to a success path) with the LDAP "no such object" code, and
     * it must record *what* was searched for, since that filter is the only clue an administrator
     * has to diagnose a misconfigured search base or filter template.
     *
     * @throws Exception
     */
    public function testAuthenticateWhenUserIsNotFoundOnTheDirectory()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);

        $this->ldap
            ->expects(self::once())
            ->method('connect')
            ->with(null, null);

        $filter = '(uid=' . $userLoginData->getLoginUser() . ')';

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn(new AttributeCollection());

        // No attributes means there is nothing to check group membership against
        $this->ldap
            ->expects(self::never())
            ->method('isUserInGroup');

        $this->eventDispatcher
            ->expects(self::once())
            ->method('notify')
            ->with(self::callback(
                static fn(Event $event) => $event->getName() === 'ldap.getAttributes'
                    && str_contains($event->getEventMessage()->composeText(), $filter)
            ));

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertFalse($out->isOk());
        self::assertEquals(LdapCodeEnum::NO_SUCH_OBJECT->value, $out->getStatusCode());
    }

    /**
     * Many directories don't populate a single "fullname" attribute; sysPass then builds one out
     * of the given name and surname. This pins the join (space-separated, in that order) so a
     * future attribute-mapping change can't silently swap or drop a part of the name.
     *
     * @throws Exception
     */
    public function testAuthenticateComposesNameFromGivenNameAndSurnameWhenFullnameIsMissing()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';
        $givenName = self::$faker->firstName();
        $surname = self::$faker->lastName();

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'name' => $givenName,
                                                   'sn' => $surname,
                                                   'mail' => self::$faker->email(),
                                                   'expire' => 0,
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertSame($givenName . ' ' . $surname, $out->getName());
    }

    /**
     * When the directory has neither a fullname nor a given name/surname, joining two empty
     * strings with a space must not leave a stray leading/trailing space in the account's display
     * name (the production code trims the composed string for exactly this reason).
     *
     * @throws Exception
     */
    public function testAuthenticateComposesEmptyNameWhenNoNameAttributesArePresent()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'mail' => self::$faker->email(),
                                                   'expire' => 0,
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertSame('', $out->getName());
    }

    /**
     * Some directories return a multi-valued "mail" attribute as an array rather than a scalar.
     * Casting an array straight to string yields the literal word "Array" (silently, with a PHP
     * notice) instead of an address, so this pins that the first element is used, not the array
     * itself.
     *
     * @throws Exception
     */
    public function testAuthenticateUsesFirstElementWhenMailIsAnArray()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';
        $primaryMail = self::$faker->email();

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'fullname' => self::$faker->name(),
                                                   'mail' => [$primaryMail, self::$faker->email()],
                                                   'expire' => 0,
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertSame($primaryMail, $out->getEmail());
    }

    /**
     * The counterpart of the array case above: a directory that returns "mail" as a plain scalar
     * must still end up with that same address, not some mangled/array-wrapped value.
     *
     * @throws Exception
     */
    public function testAuthenticateUsesMailDirectlyWhenItIsAScalar()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';
        $mail = self::$faker->email();

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'fullname' => self::$faker->name(),
                                                   'mail' => $mail,
                                                   'expire' => 0,
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertSame($mail, $out->getEmail());
    }

    /**
     * A directory entry without a "mail" attribute at all must leave the email unset rather than
     * storing an empty string (the account-provisioning code downstream treats "no email" and
     * "empty email" differently).
     *
     * @throws Exception
     */
    public function testAuthenticateLeavesEmailUnsetWhenMailAttributeIsMissing()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'fullname' => self::$faker->name(),
                                                   'expire' => 0,
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertNull($out->getEmail());
    }

    /**
     * A directory entry without a "expire" attribute at all must default to 0 (never expiring),
     * not to some falsy-but-wrong value, and that default must not by itself cause the account to
     * be treated as expired.
     *
     * @throws Exception
     */
    public function testAuthenticateDefaultsExpireToZeroWhenMissing()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';

        $attributes = new AttributeCollection([
                                                   'dn' => self::$faker->userName(),
                                                   'group' => [],
                                                   'fullname' => self::$faker->name(),
                                                   'mail' => self::$faker->email(),
                                               ]);

        $this->mockLookup($userLoginData, $ldapActions, $filter, $attributes, true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertSame(0, $out->getExpire());
        self::assertTrue($out->isOk());
    }

    /**
     * The group membership check must be handed exactly what the directory returned for "group",
     * cast to an array. A directory that returns a single (non-multi-valued) group as a bare
     * string, rather than a one-element array, must still be usable by the membership check.
     *
     * @throws Exception
     */
    public function testAuthenticateCastsASingleGroupValueToAnArray()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';
        $userDn = self::$faker->userName();
        $singleGroup = self::$faker->company();

        $attributes = new AttributeCollection([
                                                   'dn' => $userDn,
                                                   'group' => $singleGroup,
                                                   'fullname' => self::$faker->name(),
                                                   'mail' => self::$faker->email(),
                                                   'expire' => 0,
                                               ]);

        // A successful lookup reconnects with the resolved dn/password, hence connect() twice.
        $this->ldap
            ->expects(self::exactly(2))
            ->method('connect');

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->with($userDn, $userLoginData->getLoginUser(), [$singleGroup])
            ->willReturn(true);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertTrue($out->isOk());
    }

    /**
     * A directory entry without a "group" attribute at all must hand the group check an empty
     * array rather than null — the membership check declares an array parameter, and this is the
     * only path that exercises the "no groups returned" shape rather than "attribute missing" one.
     *
     * @throws Exception
     */
    public function testAuthenticateDefaultsGroupToAnEmptyArrayWhenMissing()
    {
        $userLoginData = new UserLoginDto();
        $userLoginData->setLoginUser(self::$faker->userName());
        $userLoginData->setLoginPass(self::$faker->password());

        $ldapActions = $this->createMock(LdapActionsService::class);
        $filter = 'test';
        $userDn = self::$faker->userName();

        $attributes = new AttributeCollection([
                                                   'dn' => $userDn,
                                                   'fullname' => self::$faker->name(),
                                                   'mail' => self::$faker->email(),
                                                   'expire' => 0,
                                               ]);

        $this->ldap
            ->expects(self::once())
            ->method('connect')
            ->with(null, null);

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->with($userDn, $userLoginData->getLoginUser(), [])
            ->willReturn(false);

        $out = $this->ldapAuth->authenticate($userLoginData);

        self::assertFalse($out->isOk());
        self::assertEquals(LdapAuthService::ACCOUNT_NO_GROUPS, $out->getStatusCode());
    }

    /**
     * Shared plumbing for the attribute-parsing tests above: they only care about the parsed
     * LdapAuthData, not about re-asserting the connect/filter/actions wiring already covered by
     * testAuthenticate().
     */
    private function mockLookup(
        UserLoginDto                 $userLoginData,
        LdapActionsService|MockObject $ldapActions,
        string                        $filter,
        AttributeCollection           $attributes,
        bool                          $isInGroup
    ): void {
        // A successful lookup reconnects with the resolved dn/password, so connect() is called
        // twice in that case; a failed one never gets that far.
        $this->ldap
            ->expects(self::exactly($isInGroup ? 2 : 1))
            ->method('connect');

        $this->ldap
            ->expects(self::once())
            ->method('getUserDnFilter')
            ->with($userLoginData->getLoginUser())
            ->willReturn($filter);

        $this->ldap
            ->expects(self::once())
            ->method('actions')
            ->willReturn($ldapActions);

        $ldapActions
            ->expects(self::once())
            ->method('getAttributes')
            ->with($filter)
            ->willReturn($attributes);

        $this->ldap
            ->expects(self::once())
            ->method('isUserInGroup')
            ->willReturn($isInGroup);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ldap = $this->createMock(LdapService::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->configData = $this->createMock(ConfigDataInterface::class);

        $this->ldapAuth = new LdapAuth($this->ldap, $this->eventDispatcher, $this->configData);
    }
}
