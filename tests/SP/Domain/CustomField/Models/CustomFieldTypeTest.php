<?php

declare(strict_types=1);

namespace SP\Tests\Domain\CustomField\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Common\Models\ItemWithIdAndNameModel;
use SP\Domain\CustomField\Models\CustomFieldType;

#[Group('unitary')]
class CustomFieldTypeTest extends TestCase
{
    public function testDefaults(): void
    {
        $type = new CustomFieldType();

        self::assertNull($type->getId());
        self::assertNull($type->getName());
        self::assertNull($type->getText());
    }

    public function testConstructorSetsProperties(): void
    {
        $type = new CustomFieldType([
            'id' => 1,
            'name' => 'password',
            'text' => 'Password',
        ]);

        self::assertSame(1, $type->getId());
        self::assertSame('password', $type->getName());
        self::assertSame('Password', $type->getText());
    }

    public function testImplementsItemWithIdAndNameModel(): void
    {
        $type = new CustomFieldType(['id' => 1, 'name' => 'text']);

        self::assertInstanceOf(ItemWithIdAndNameModel::class, $type);
    }

    public function testMutate(): void
    {
        $original = new CustomFieldType(['id' => 1, 'name' => 'text', 'text' => 'Text']);
        $mutated = $original->mutate(['name' => 'textarea']);

        self::assertNotSame($original, $mutated);
        self::assertSame('textarea', $mutated->getName());
        self::assertSame('Text', $mutated->getText());
    }
}
