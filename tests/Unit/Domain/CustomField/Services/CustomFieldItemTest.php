<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\CustomField\Services;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\CustomField\Services\CustomFieldItem;

#[Group('unitary')]
class CustomFieldItemTest extends TestCase
{
    private function makeItem(array $overrides = []): CustomFieldItem
    {
        return new CustomFieldItem(...array_merge([
            'required' => true,
            'showInList' => false,
            'help' => 'Enter value',
            'definitionId' => 5,
            'definitionName' => 'API Key',
            'typeId' => 2,
            'typeName' => 'password',
            'typeText' => 'Password',
            'moduleId' => 10,
            'formId' => 'customfield_5',
            'value' => 'secret123',
            'isEncrypted' => true,
            'isValueEncrypted' => false,
        ], $overrides));
    }

    public function testConstructorSetsAllProperties(): void
    {
        $item = $this->makeItem();

        self::assertTrue($item->required);
        self::assertFalse($item->showInList);
        self::assertSame('Enter value', $item->help);
        self::assertSame(5, $item->definitionId);
        self::assertSame('API Key', $item->definitionName);
        self::assertSame(2, $item->typeId);
        self::assertSame('password', $item->typeName);
        self::assertSame('Password', $item->typeText);
        self::assertSame(10, $item->moduleId);
        self::assertSame('customfield_5', $item->formId);
        self::assertSame('secret123', $item->value);
        self::assertTrue($item->isEncrypted);
        self::assertFalse($item->isValueEncrypted);
    }

    public function testJsonSerializeExcludesInternalFields(): void
    {
        $item = $this->makeItem();
        $json = $item->jsonSerialize();

        self::assertArrayHasKey('required', $json);
        self::assertArrayHasKey('typeName', $json);
        self::assertArrayHasKey('value', $json);
        self::assertArrayHasKey('isEncrypted', $json);
        self::assertArrayHasKey('isValueEncrypted', $json);

        self::assertArrayNotHasKey('definitionId', $json);
        self::assertArrayNotHasKey('definitionName', $json);
        self::assertArrayNotHasKey('formId', $json);
    }

    public function testJsonSerializeValues(): void
    {
        $item = $this->makeItem();
        $json = $item->jsonSerialize();

        self::assertTrue($json['required']);
        self::assertFalse($json['showInList']);
        self::assertSame('Enter value', $json['help']);
        self::assertSame(2, $json['typeId']);
        self::assertSame('password', $json['typeName']);
        self::assertSame('Password', $json['typeText']);
        self::assertSame(10, $json['moduleId']);
        self::assertSame('secret123', $json['value']);
        self::assertTrue($json['isEncrypted']);
        self::assertFalse($json['isValueEncrypted']);
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $item = $this->makeItem();

        $encoded = json_encode($item);
        $decoded = json_decode($encoded, true);

        self::assertTrue($decoded['required']);
        self::assertSame('password', $decoded['typeName']);
        self::assertSame('secret123', $decoded['value']);
    }

    public function testValueCanBeNull(): void
    {
        $item = $this->makeItem(['value' => null]);

        self::assertNull($item->value);

        $json = $item->jsonSerialize();
        self::assertNull($json['value']);
    }

    public function testPropertiesAreReadonly(): void
    {
        $item = $this->makeItem();

        self::expectException(\Error::class);
        $item->required = false;
    }
}
