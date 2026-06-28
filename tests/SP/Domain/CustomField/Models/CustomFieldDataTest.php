<?php

declare(strict_types=1);

namespace SP\Tests\Domain\CustomField\Models;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\CustomField\Models\CustomFieldData;

#[Group('unitary')]
class CustomFieldDataTest extends TestCase
{
    public function testDefaults(): void
    {
        $model = new CustomFieldData();

        self::assertNull($model->getModuleId());
        self::assertNull($model->getItemId());
        self::assertNull($model->getDefinitionId());
        self::assertNull($model->getData());
        self::assertNull($model->getKey());
    }

    public function testConstructorSetsProperties(): void
    {
        $model = new CustomFieldData([
            'moduleId' => 10,
            'itemId' => 42,
            'definitionId' => 3,
            'data' => 'encrypted-blob',
            'key' => 'aes-key',
        ]);

        self::assertSame(10, $model->getModuleId());
        self::assertSame(42, $model->getItemId());
        self::assertSame(3, $model->getDefinitionId());
        self::assertSame('encrypted-blob', $model->getData());
        self::assertSame('aes-key', $model->getKey());
    }

    public function testToArrayShape(): void
    {
        $model = new CustomFieldData([
            'moduleId' => 10,
            'itemId' => 42,
            'definitionId' => 3,
            'data' => 'blob',
            'key' => 'k',
        ]);

        $array = $model->toArray();

        self::assertSame(
            ['moduleId', 'itemId', 'definitionId', 'data', 'key'],
            array_keys($array)
        );
    }

    public function testMutate(): void
    {
        $original = new CustomFieldData(['moduleId' => 10, 'itemId' => 1]);
        $mutated = $original->mutate(['itemId' => 99]);

        self::assertNotSame($original, $mutated);
        self::assertSame(10, $mutated->getModuleId());
        self::assertSame(99, $mutated->getItemId());
        self::assertSame(1, $original->getItemId());
    }

    public function testGetCols(): void
    {
        $cols = CustomFieldData::getCols();

        self::assertContains('moduleId', $cols);
        self::assertContains('data', $cols);
        self::assertContains('key', $cols);
    }
}
