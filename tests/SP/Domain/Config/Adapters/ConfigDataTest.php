<?php

declare(strict_types=1);

namespace SP\Tests\Domain\Config\Adapters;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;

/**
 * Class ConfigDataTest
 *
 * Regression coverage for the LDAP boolean flags: their setters used to store
 * (int) while the getters are declared ": bool" under strict_types, which
 * fatals with a TypeError as soon as the value is read back (e.g. LdapAuth
 * checking isLdapDatabaseEnabled(), or the LDAP test-connection check reading
 * isLdapTlsEnabled()).
 */
#[Group('unitary')]
class ConfigDataTest extends TestCase
{
    public function testSetLdapTlsEnabledRoundTripsAsBool(): void
    {
        $configData = new ConfigData();

        $result = $configData->setLdapTlsEnabled(true);

        self::assertSame($configData, $result);
        self::assertTrue($configData->isLdapTlsEnabled());
        self::assertIsBool($configData->getAttributes()[ConfigDataInterface::LDAP_TLS_ENABLED]);

        $configData->setLdapTlsEnabled(false);

        self::assertFalse($configData->isLdapTlsEnabled());
        self::assertIsBool($configData->getAttributes()[ConfigDataInterface::LDAP_TLS_ENABLED]);
    }

    public function testSetLdapDatabaseEnabledRoundTripsAsBool(): void
    {
        $configData = new ConfigData();

        $result = $configData->setLdapDatabaseEnabled(true);

        self::assertSame($configData, $result);
        self::assertTrue($configData->isLdapDatabaseEnabled());
        self::assertIsBool($configData->getAttributes()[ConfigDataInterface::LDAP_DATABASE_ENABLED]);

        $configData->setLdapDatabaseEnabled(false);

        self::assertFalse($configData->isLdapDatabaseEnabled());
        self::assertIsBool($configData->getAttributes()[ConfigDataInterface::LDAP_DATABASE_ENABLED]);
    }
}
