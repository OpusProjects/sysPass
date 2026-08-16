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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Auth\Providers\Ldap\LdapParams;
use SP\Domain\Auth\Providers\Ldap\LdapTypeEnum;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The one field an administrator types by hand, and the only thing that reads it.
 *
 * `ldapServer` is free text: a host, optionally a scheme, optionally a port. Everything downstream
 * takes what this class makes of it — `LdapConnection` hands the host, port and transport to
 * Laminas, and `LdapMsAds::pickServer()` slices the host apart to build a `_msdcs` DNS query. A
 * mis-parse here is a connection to the wrong place, reported as the directory being unreachable.
 *
 * Two ways it used to be wrong, both fixed and both pinned below:
 *
 *  - The pattern was unanchored and kept the scheme inside the host group, so `http://evil.com`
 *    matched a *prefix* and produced the host `http` with no error at all.
 *  - With the scheme in the host, `ldaps://host:1636` reached Laminas as the connect string
 *    `ldaps://host`. Laminas uses a scheme-bearing host verbatim and appends no port, so the port
 *    the administrator configured was dropped in favour of 636 — silently, and only for people who
 *    wrote the scheme.
 */
#[Group('unitary')]
class LdapParamsTest extends UnitaryTestCase
{
    /**
     * @return array<string, array{string, string, int, bool}>
     */
    public static function serverStringProvider(): array
    {
        // server string, host, port, ssl
        return [
            'bare host' => ['directory.example.com', 'directory.example.com', 389, false],
            'host and port' => ['directory.example.com:1389', 'directory.example.com', 1389, false],
            'ldap scheme' => ['ldap://directory.example.com', 'directory.example.com', 389, false],
            'ldaps scheme takes its own default port' => ['ldaps://directory.example.com', 'directory.example.com', 636, true],
            'an explicit port still wins' => ['ldaps://directory.example.com:1636', 'directory.example.com', 1636, true],
            'the scheme may be in any case' => ['LDAPS://Directory.Example.Com', 'Directory.Example.Com', 636, true],
            'an address is a host like any other' => ['10.0.0.1:1636', '10.0.0.1', 1636, false],
        ];
    }

    /**
     * @throws ValidationException
     */
    #[Test]
    #[DataProvider('serverStringProvider')]
    public function theServerStringIsParsedWhole(string $server, string $host, int $port, bool $ssl): void
    {
        $params = self::build($server);

        // The host carries no scheme: Laminas only appends the port when it builds the connect
        // string itself, which it does exactly when the host it is given has no scheme.
        self::assertSame($host, $params->getServer());
        self::assertSame($port, $params->getPort());
        self::assertSame($ssl, $params->isSslEnabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableServerStringProvider(): array
    {
        return [
            'a scheme that is not LDAP' => ['http://evil.com'],
            'a bare scheme' => ['ldaps://'],
            'spaces' => ['not a host'],
            'trailing junk' => ['directory.example.com/path'],
            'a port that is not a number' => ['directory.example.com:ldaps'],
            'empty' => [''],
        ];
    }

    /**
     * A server string that is not one is refused, rather than truncated to whatever prefix happens
     * to look like a host.
     */
    #[Test]
    #[DataProvider('unusableServerStringProvider')]
    public function aServerStringThatIsNotOneIsRefused(string $server): void
    {
        $this->expectException(ValidationException::class);

        self::build($server);
    }

    /**
     * StartTLS and LDAPS are different things and are reported separately: one is negotiated on the
     * plain port, the other is the transport the scheme selects.
     *
     * @throws ValidationException
     */
    #[Test]
    public function startTlsAndLdapsAreIndependent(): void
    {
        self::assertFalse(self::build('directory.example.com')->isSslEnabled());
        self::assertTrue(self::build('directory.example.com', tls: true)->isTlsEnabled());
        self::assertFalse(self::build('ldaps://directory.example.com')->isTlsEnabled());
        self::assertTrue(self::build('ldaps://directory.example.com')->isSslEnabled());
    }

    /**
     * Every one of the four is needed to reach a directory at all, so a configuration missing any
     * of them is refused before anything tries to connect.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aConfigurationMissingWhatItNeedsIsRefused(): void
    {
        foreach (['server', 'type', 'bindUser', 'bindPass'] as $missing) {
            $params = self::params('directory.example.com');
            $params[$missing] = '';

            try {
                LdapParams::fromArray($params);

                self::fail(sprintf('A configuration with no %s was accepted', $missing));
            } catch (ValidationException $e) {
                self::assertStringContainsString('LDAP', $e->getMessage());
            }
        }
    }

    /**
     * An unrecognised type falls back to standard LDAP rather than failing: the filters differ
     * between directory kinds, and the standard ones are the conservative choice.
     *
     * @throws ValidationException
     */
    #[Test]
    public function anUnknownDirectoryTypeFallsBackToStandard(): void
    {
        self::assertSame(LdapTypeEnum::ADS, self::build('a.example.com', type: LdapTypeEnum::ADS->value)->getType());
        self::assertSame(LdapTypeEnum::STD, self::build('a.example.com', type: 987)->getType());
    }

    /**
     * @throws ValidationException
     */
    private static function build(string $server, bool $tls = false, int $type = 1): LdapParams
    {
        return LdapParams::fromArray(self::params($server, $tls, $type));
    }

    /**
     * @return array<string, mixed>
     */
    private static function params(string $server, bool $tls = false, int $type = 1): array
    {
        return [
            'server' => $server,
            'type' => $type,
            'bindUser' => 'cn=bind,dc=example,dc=com',
            'bindPass' => 'a_password',
            'searchBase' => 'dc=example,dc=com',
            'group' => null,
            'tlsEnabled' => $tls,
            'filterUserObject' => null,
            'filterGroupObject' => null,
            'filterUserAttributes' => null,
            'filterGroupAttributes' => null,
        ];
    }
}
