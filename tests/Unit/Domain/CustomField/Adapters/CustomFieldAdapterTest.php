<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\CustomField\Adapters;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\CustomField\Adapters\CustomField;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\CustomField\Services\CustomFieldItem;

#[Group('unitary')]
class CustomFieldAdapterTest extends TestCase
{
    private function makeAdapter(bool $mayViewPass = true): CustomField
    {
        $configData = $this->createStub(ConfigDataInterface::class);

        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturnCallback(
            static fn(int $action) => $action === AclActionsInterface::CUSTOMFIELD_VIEW_PASS ? $mayViewPass : true
        );

        return new CustomField($configData, 'https://example.com', $acl);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeItem(array $overrides = []): CustomFieldItem
    {
        return new CustomFieldItem(
            required: $overrides['required'] ?? false,
            showInList: false,
            help: '',
            definitionId: 5,
            definitionName: 'API Key',
            typeId: 2,
            typeName: 'password',
            typeText: 'Password',
            moduleId: 10,
            formId: 'cf_5',
            value: $overrides['value'] ?? 'secret',
            isEncrypted: $overrides['isEncrypted'] ?? true,
            isValueEncrypted: $overrides['isValueEncrypted'] ?? true,
        );
    }

    /**
     * A stored secret is not handed to a caller who may not see it.
     *
     * `ItemTrait::getCustomFieldsForItem()` decrypts whenever the row carries a key, without asking
     * who is looking — the deciding is left to whoever renders it. The theme does decide:
     * `aux-customfields.inc` prints `***` unless `showViewCustomPass`, which `AccountHelper` sets
     * from the account's own view-password permission. Nothing decided here, so
     * `account/view?customFields=1` answered with the decrypted value — on a token for
     * `account/view`, which the API otherwise keeps apart from `account/viewPass` precisely because
     * one of them hands out secrets. The account's own password is not in this response for the
     * same reason.
     */
    public function testAnEncryptedValueIsMaskedForACallerWhoMayNotViewPasswords(): void
    {
        $result = $this->makeAdapter(mayViewPass: false)->transform($this->makeItem());

        self::assertSame(CustomField::MASKED, $result['value']);
        self::assertTrue($result['encrypted'], 'the caller is still told the field holds a secret');
    }

    public function testAnEncryptedValueIsReturnedToACallerWhoMayViewPasswords(): void
    {
        $result = $this->makeAdapter(mayViewPass: true)->transform($this->makeItem());

        self::assertSame('secret', $result['value']);
    }

    /**
     * A field that was never encrypted is not a secret, and the permission does not apply to it —
     * masking every custom field would have been a different change, and a wrong one.
     */
    public function testAValueThatWasNeverEncryptedIsReturnedRegardless(): void
    {
        $result = $this->makeAdapter(mayViewPass: false)->transform(
            $this->makeItem(['isValueEncrypted' => false, 'isEncrypted' => false, 'value' => 'plain'])
        );

        self::assertSame('plain', $result['value']);
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
