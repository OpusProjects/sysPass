<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\ItemPreset\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\ItemPreset\Models\ItemPreset;

#[Group('unitary')]
class ItemPresetTest extends TestCase
{
    public function testDefaults(): void
    {
        $preset = new ItemPreset();

        self::assertNull($preset->getId());
        self::assertNull($preset->getType());
        self::assertNull($preset->getUserId());
        self::assertNull($preset->getUserGroupId());
        self::assertNull($preset->getUserProfileId());
        self::assertNull($preset->getFixed());
        self::assertNull($preset->getPriority());
        self::assertNull($preset->getData());
        self::assertNull($preset->getHash());
        self::assertNull($preset->getScore());
        self::assertNull($preset->getUserName());
        self::assertNull($preset->getUserProfileName());
        self::assertNull($preset->getUserGroupName());
    }

    public function testConstructorSetsProperties(): void
    {
        $preset = new ItemPreset([
            'id' => 1,
            'type' => 'password',
            'userId' => 10,
            'userGroupId' => 20,
            'userProfileId' => 30,
            'fixed' => 1,
            'priority' => 5,
            'data' => '{"length":12}',
            'hash' => 'abc123',
            'score' => 3,
            'userName' => 'admin',
            'userProfileName' => 'Admin',
            'userGroupName' => 'Admins',
        ]);

        self::assertSame(1, $preset->getId());
        self::assertSame('password', $preset->getType());
        self::assertSame(10, $preset->getUserId());
        self::assertSame(20, $preset->getUserGroupId());
        self::assertSame(30, $preset->getUserProfileId());
        self::assertSame(1, $preset->getFixed());
        self::assertSame(5, $preset->getPriority());
        self::assertSame('{"length":12}', $preset->getData());
        self::assertSame('abc123', $preset->getHash());
        self::assertSame(3, $preset->getScore());
        self::assertSame('admin', $preset->getUserName());
        self::assertSame('Admin', $preset->getUserProfileName());
        self::assertSame('Admins', $preset->getUserGroupName());
    }

    public function testMutate(): void
    {
        $original = new ItemPreset(['id' => 1, 'type' => 'password', 'priority' => 5]);
        $mutated = $original->mutate(['priority' => 10]);

        self::assertNotSame($original, $mutated);
        self::assertSame(1, $mutated->getId());
        self::assertSame(10, $mutated->getPriority());
        self::assertSame(5, $original->getPriority());
    }

    public function testGetCols(): void
    {
        $cols = ItemPreset::getCols();

        self::assertContains('id', $cols);
        self::assertContains('type', $cols);
        self::assertContains('data', $cols);
        self::assertContains('userId', $cols);
    }
}
