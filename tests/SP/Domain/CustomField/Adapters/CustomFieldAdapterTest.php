<?php

declare(strict_types=1);

namespace SP\Tests\Domain\CustomField\Adapters;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\CustomField\Adapters\CustomField;
use SP\Domain\CustomField\Services\CustomFieldItem;

#[Group('unitary')]
class CustomFieldAdapterTest extends TestCase
{
    private function makeAdapter(): CustomField
    {
        $configData = $this->createStub(ConfigDataInterface::class);

        return new CustomField($configData, 'https://example.com');
    }

    public function testTransformReturnsExpectedKeys(): void
    {
        $adapter = $this->makeAdapter();
        $item = new CustomFieldItem(
            required: true,
            showInList: false,
            help: 'Help text',
            definitionId: 5,
            definitionName: 'API Key',
            typeId: 2,
            typeName: 'password',
            typeText: 'Password',
            moduleId: 10,
            formId: 'cf_5',
            value: 'secret',
            isEncrypted: true,
            isValueEncrypted: false,
        );

        $result = $adapter->transform($item);

        self::assertSame([
            'type' => 'password',
            'typeText' => 'Password',
            'definitionId' => 5,
            'definitionName' => 'API Key',
            'help' => 'Help text',
            'value' => 'secret',
            'encrypted' => true,
            'required' => true,
        ], $result);
    }

    public function testTransformWithNullValue(): void
    {
        $adapter = $this->makeAdapter();
        $item = new CustomFieldItem(
            required: false,
            showInList: true,
            help: '',
            definitionId: 1,
            definitionName: 'Notes',
            typeId: 1,
            typeName: 'text',
            typeText: 'Text',
            moduleId: 10,
            formId: 'cf_1',
            value: null,
            isEncrypted: false,
            isValueEncrypted: false,
        );

        $result = $adapter->transform($item);

        self::assertNull($result['value']);
        self::assertFalse($result['encrypted']);
        self::assertFalse($result['required']);
    }
}
