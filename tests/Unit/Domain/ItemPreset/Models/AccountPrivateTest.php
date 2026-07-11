<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\ItemPreset\Models\AccountPrivate;

#[Group('unitary')]
class AccountPrivateTest extends TestCase
{
    public function testDefaults(): void
    {
        $ap = new AccountPrivate();

        self::assertFalse($ap->isPrivateUser());
        self::assertFalse($ap->isPrivateGroup());
    }

    public function testConstructorSetsProperties(): void
    {
        $ap = new AccountPrivate(privateUser: true, privateGroup: true);

        self::assertTrue($ap->isPrivateUser());
        self::assertTrue($ap->isPrivateGroup());
    }

    public function testPartialConstruction(): void
    {
        $ap = new AccountPrivate(privateUser: true);

        self::assertTrue($ap->isPrivateUser());
        self::assertFalse($ap->isPrivateGroup());
    }

    public function testIsReadonly(): void
    {
        $ap = new AccountPrivate();

        self::expectException(\Error::class);
        $ap->privateUser = true;
    }
}
