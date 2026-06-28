<?php

declare(strict_types=1);

namespace SP\Tests\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
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

    public function testIsReadonly(): void
    {
        $st = new SessionTimeout('10.0.0.1', 60);

        self::expectException(\Error::class);
        $st->timeout = 999;
    }
}
