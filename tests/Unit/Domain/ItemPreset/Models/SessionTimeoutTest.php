<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SP\Domain\Core\Exceptions\InvalidArgumentException;
use SP\Domain\ItemPreset\Models\SessionTimeout;

#[Group('unitary')]
class SessionTimeoutTest extends TestCase
{
    public function testPlainAddress(): void
    {
        $st = new SessionTimeout('192.168.1.1', 3600);

        self::assertSame('192.168.1.1', $st->getAddress());
        self::assertSame('255.255.255.255', $st->getMask());
        self::assertSame(3600, $st->getTimeout());
    }

    public function testCidrNotation(): void
    {
        $st = new SessionTimeout('10.0.0.0/24', 1800);

        self::assertSame('10.0.0.0', $st->getAddress());
        self::assertSame('255.255.255.0', $st->getMask());
        self::assertSame(1800, $st->getTimeout());
    }

    public function testCidr16(): void
    {
        $st = new SessionTimeout('172.16.0.0/16', 7200);

        self::assertSame('172.16.0.0', $st->getAddress());
        self::assertSame('255.255.0.0', $st->getMask());
        self::assertSame(7200, $st->getTimeout());
    }

    public function testInvalidAddressThrows(): void
    {
        self::expectException(InvalidArgumentException::class);

        new SessionTimeout('not-an-ip', 3600);
    }

    /**
     * The address pattern checks only the shape — any three digits as an octet, any two as a
     * prefix — so these used to get through: a prefix past 32 died in cidrToDec() with an
     * ArithmeticError (a 500), and an octet past 255 was stored and then refused on every request.
     */
    #[TestWith(['10.0.0.0/33'])]
    #[TestWith(['10.0.0.0/99'])]
    #[TestWith(['999.1.1.1'])]
    #[TestWith(['10.0.0.256/24'])]
    #[TestWith(['10.0.0.0/255.255.256.0'])]
    public function testOutOfRangeAddressThrows(string $address): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid IP');

        new SessionTimeout($address, 3600);
    }

    #[TestWith(['0.0.0.0/0', '0.0.0.0'])]
    #[TestWith(['10.0.0.1/32', '255.255.255.255'])]
    #[TestWith(['10.0.0.0/255.255.255.0', '255.255.255.0'])]
    public function testPrefixBoundsAndDottedMask(string $address, string $expectedMask): void
    {
        self::assertSame($expectedMask, (new SessionTimeout($address, 60))->getMask());
    }

    public function testIsReadonly(): void
    {
        $st = new SessionTimeout('10.0.0.1', 60);

        self::expectException(\Error::class);
        $st->timeout = 999;
    }
}
