<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\CustomField\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\CustomField\Models\CustomFieldDefinition;
use SP\Domain\CustomField\Models\CustomFieldDefinitionList;

#[Group('unitary')]
class CustomFieldDefinitionTest extends TestCase
{
    public function testDefaults(): void
    {
        $def = new CustomFieldDefinition();

        self::assertNull($def->getId());
        self::assertNull($def->getName());
        self::assertNull($def->getModuleId());
        self::assertNull($def->getRequired());
        self::assertNull($def->getHelp());
        self::assertNull($def->getShowInList());
        self::assertNull($def->getTypeId());
        self::assertNull($def->getIsEncrypted());
    }

    public function testConstructorSetsProperties(): void
    {
        $def = new CustomFieldDefinition([
            'id' => 5,
            'name' => 'API Key',
            'moduleId' => 10,
            'required' => 1,
            'help' => 'Enter your API key',
            'showInList' => 1,
            'typeId' => 2,
            'isEncrypted' => 1,
        ]);

        self::assertSame(5, $def->getId());
        self::assertSame('API Key', $def->getName());
        self::assertSame(10, $def->getModuleId());
        self::assertSame(1, $def->getRequired());
        self::assertSame('Enter your API key', $def->getHelp());
        self::assertSame(1, $def->getShowInList());
        self::assertSame(2, $def->getTypeId());
        self::assertSame(1, $def->getIsEncrypted());
    }

    public function testMutate(): void
    {
        $original = new CustomFieldDefinition(['id' => 1, 'name' => 'Token']);
        $mutated = $original->mutate(['name' => 'Secret']);

        self::assertNotSame($original, $mutated);
        self::assertSame(1, $mutated->getId());
        self::assertSame('Secret', $mutated->getName());
        self::assertSame('Token', $original->getName());
    }

    public function testDefinitionListExtendsDefinition(): void
    {
        $list = new CustomFieldDefinitionList([
            'id' => 3,
            'name' => 'Region',
            'typeName' => 'text',
        ]);

        self::assertInstanceOf(CustomFieldDefinition::class, $list);
        self::assertSame(3, $list->getId());
        self::assertSame('Region', $list->getName());
        self::assertSame('text', $list->getTypeName());
    }

    public function testDefinitionListDefaultTypeName(): void
    {
        $list = new CustomFieldDefinitionList();
        self::assertNull($list->getTypeName());
    }
}
