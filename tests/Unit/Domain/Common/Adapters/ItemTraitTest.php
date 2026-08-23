<?php
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace SP\Tests\Unit\Domain\Common\Adapters;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Domain\Common\Adapters\ItemTrait;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\CustomField\Models\CustomFieldData;
use SP\Domain\CustomField\Adapters\CustomField;
use SP\Domain\CustomField\Services\CustomFieldItem;
use SP\Domain\Http\Ports\RequestService;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Every controller that owns an item with custom fields uses this trait to read them out of a
 * request and write them back, so a mistake here loses or leaks a field on all of them at once.
 *
 * The parts worth pinning are the ones that are not a passthrough: an encrypted field has to be
 * decrypted before it reaches the view, and an emptied one has to be deleted rather than saved as
 * an empty string, since only the delete removes the stored ciphertext.
 */
#[Group('unitary')]
class ItemTraitTest extends UnitaryTestCase
{
    private const MODULE_ID = 10;

    private ItemTraitFixture $host;

    /**
     * A field that was never encrypted is handed over as stored.
     */
    #[Test]
    public function aPlainFieldIsReadAsStored()
    {
        $fields = $this->whenReadingTheFieldsOf($this->buildRow(['data' => 'a value']));

        self::assertCount(1, $fields);
        self::assertSame('a value', $fields[0]->value);
        self::assertFalse($fields[0]->isValueEncrypted);
    }

    /**
     * The stored row's columns arrive as strings; the item they become is typed, and the view uses
     * the definition id to key the field it renders.
     */
    #[Test]
    public function aFieldKeepsWhatItWasStoredWith()
    {
        $fields = $this->whenReadingTheFieldsOf($this->buildRow(['data' => 'a value']));

        self::assertInstanceOf(CustomFieldItem::class, $fields[0]);
        self::assertSame(4, $fields[0]->definitionId);
        self::assertSame(1, $fields[0]->typeId);
        self::assertSame(self::MODULE_ID, $fields[0]->moduleId);
        self::assertTrue($fields[0]->required);
        self::assertFalse($fields[0]->showInList);
    }

    /**
     * An encrypted one is decrypted on the way out — what is stored is ciphertext, and the view
     * shows the value.
     */
    #[Test]
    public function anEncryptedFieldIsDecrypted()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->method('getBy')->willReturn([$this->buildRow(['data' => 'ciphertext', 'key' => 'a key'])]);
        $service->expects(self::once())
                ->method('decrypt')
                ->with('ciphertext', 'a key')
                ->willReturn('the secret');

        $fields = $this->host->readCustomFields(self::MODULE_ID, 1, $service);

        self::assertSame('the secret', $fields[0]->value);
        self::assertTrue($fields[0]->isValueEncrypted);
    }

    /**
     * Ciphertext with no key beside it cannot be decrypted, so it is left alone rather than handed
     * to the decryption with an empty key.
     */
    #[Test]
    public function aFieldWithoutAKeyIsNotDecrypted()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->method('getBy')->willReturn([$this->buildRow(['data' => 'ciphertext', 'key' => null])]);
        $service->expects(self::never())->method('decrypt');

        $fields = $this->host->readCustomFields(self::MODULE_ID, 1, $service);

        self::assertSame('ciphertext', $fields[0]->value);
        self::assertFalse($fields[0]->isValueEncrypted);
    }

    /**
     * A value that looks like a link is turned into one. The templates escape the field value
     * before printing it, so this is markup for the read-only views, not a way to inject any.
     */
    #[Test]
    public function aLinkValueIsMadeClickable()
    {
        $service = $this->createStub(CustomFieldDataService::class);
        $service->method('getBy')->willReturn([$this->buildRow(['data' => 'ciphertext', 'key' => 'a key'])]);
        $service->method('decrypt')->willReturn('https://example.invalid/x');

        $fields = $this->host->readCustomFields(self::MODULE_ID, 1, $service);

        self::assertSame(
            '<a href="https://example.invalid/x" target="_blank">https://example.invalid/x</a>',
            $fields[0]->value
        );
    }

    /**
     * The form id is derived from the definition's name, and has to survive a name with spaces or
     * punctuation in it — it is what the posted field is keyed by.
     */
    #[Test]
    public function theFormIdIsDerivedFromTheDefinitionName()
    {
        $fields = $this->whenReadingTheFieldsOf($this->buildRow(['definitionName' => 'Server Name (2)']));

        self::assertSame('cf_servername2', $fields[0]->formId);
    }

    /**
     * Nothing stored means nothing to show, not a row of empty fields.
     */
    #[Test]
    public function anItemWithoutCustomFieldsReadsAsEmpty()
    {
        $service = $this->createStub(CustomFieldDataService::class);
        $service->method('getBy')->willReturn([]);

        self::assertSame([], $this->host->readCustomFields(self::MODULE_ID, 1, $service));
    }

    /**
     * Saving a new item writes one row per field that was filled in.
     */
    #[Test]
    public function creatingWritesTheFieldsThatWereFilledIn()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::once())
                ->method('create')
                ->with(
                    self::callback(
                        static fn(CustomFieldData $data) => $data->getItemId() === 7
                                                            && $data->getModuleId() === self::MODULE_ID
                                                            && $data->getDefinitionId() === 4
                                                            && $data->getData() === 'a value'
                    )
                );

        $this->host->addCustomFields(self::MODULE_ID, 7, $this->givenThePostedFields([4 => 'a value']), $service);
    }

    /**
     * A field left blank is not stored at all, so an item does not accumulate an empty row for
     * every definition that exists.
     */
    #[Test]
    public function creatingSkipsTheFieldsLeftBlank()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::never())->method('create');

        $this->host->addCustomFields(self::MODULE_ID, 7, $this->givenThePostedFields([4 => '']), $service);
    }

    /**
     * A request carrying no custom fields at all touches nothing.
     */
    #[Test]
    public function creatingWithNoFieldsPostedWritesNothing()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::never())->method('create');

        $this->host->addCustomFields(self::MODULE_ID, 7, $this->givenThePostedFields(null), $service);
    }

    #[Test]
    public function updatingSavesTheFieldsThatHaveAValue()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::once())
                ->method('updateOrCreate')
                ->with(self::callback(static fn(CustomFieldData $data) => $data->getData() === 'a new value'));

        $this->host->updateCustomFields(
            self::MODULE_ID,
            7,
            $this->givenThePostedFields([4 => 'a new value']),
            $service
        );
    }

    /**
     * Clearing a field deletes the row rather than saving an empty one. Only the delete removes the
     * stored value, so saving the blank would leave the old ciphertext behind.
     */
    #[Test]
    public function clearingAFieldDeletesItRatherThanSavingItEmpty()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::never())->method('updateOrCreate');
        $service->expects(self::once())->method('delete')->with([7], self::MODULE_ID);

        $this->host->updateCustomFields(self::MODULE_ID, 7, $this->givenThePostedFields([4 => '']), $service);
    }

    /**
     * A masked value is not saved over the secret it stands in for.
     *
     * The form renders a secret the viewer may not see as `***`, and the browser posts that back
     * verbatim when the form is saved without the field being touched. Storing it encrypted the
     * mask over the real value, unrecoverably — so editing anything else about an item destroyed
     * every custom field secret on it that the editor was not allowed to see.
     */
    #[Test]
    public function updatingDoesNotSaveTheMaskOverTheSecretItStandsFor()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::never())->method('updateOrCreate');
        $service->expects(self::never())->method('delete');

        $this->host->updateCustomFields(
            self::MODULE_ID,
            7,
            $this->givenThePostedFields([4 => CustomField::MASKED]),
            $service
        );
    }

    /**
     * Nor is one copied into a new item. The copy form is prefilled from the original, so a secret
     * the copier may not see arrives masked and would otherwise become the copy's stored value.
     */
    #[Test]
    public function creatingDoesNotSaveTheMaskOverTheSecretItStandsFor()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::never())->method('create');

        $this->host->addCustomFields(
            self::MODULE_ID,
            7,
            $this->givenThePostedFields([4 => CustomField::MASKED]),
            $service
        );
    }

    /**
     * The fields either side of a masked one are still saved — the mask is skipped, not the
     * request. Without this the test above is satisfied by a guard that drops everything.
     */
    #[Test]
    public function aMaskedFieldDoesNotStopTheOthersBeingSaved()
    {
        $saved = [];

        $service = $this->createStub(CustomFieldDataService::class);
        $service->method('updateOrCreate')->willReturnCallback(
            static function ($customFieldData) use (&$saved): void {
                $saved[] = $customFieldData->getDefinitionId();
            }
        );

        $this->host->updateCustomFields(
            self::MODULE_ID,
            7,
            $this->givenThePostedFields([4 => 'kept', 5 => CustomField::MASKED, 6 => 'also kept']),
            $service
        );

        self::assertSame([4, 6], $saved);
    }

    /**
     * Deleting an item takes its fields with it, and a single id is accepted where the service
     * wants a list.
     */
    #[Test]
    public function deletingASingleItemPassesItAsAList()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::once())->method('delete')->with([7], self::MODULE_ID);

        $this->host->deleteCustomFields(self::MODULE_ID, 7, $service);
    }

    /**
     * A bulk delete keeps the whole list.
     */
    #[Test]
    public function deletingSeveralItemsKeepsTheList()
    {
        $service = $this->createMock(CustomFieldDataService::class);
        $service->expects(self::once())->method('delete')->with([7, 8, 9], self::MODULE_ID);

        $this->host->deleteCustomFields(self::MODULE_ID, [7, 8, 9], $service);
    }

    /**
     * Every listing builds its search out of the request the same way, and the page size falls back
     * to the configured limit when the browser did not send one.
     */
    #[Test]
    public function theSearchIsBuiltFromTheRequest()
    {
        $request = $this->createStub(RequestService::class);
        $request->method('analyzeString')
                ->willReturnCallback(static fn(string $param) => $param === 'search' ? 'something' : null);
        $request->method('analyzeInt')->willReturnCallback(static fn(string $param, ?int $default) => $default);

        $search = $this->host->searchData(25, $request);

        self::assertInstanceOf(ItemSearchDto::class, $search);
        self::assertSame('something', $search->getSearchString());
        self::assertSame(0, $search->getLimitStart());
        self::assertSame(25, $search->getLimitCount(), 'the configured limit is the fallback page size');
    }

    #[Test]
    public function theSelectedItemsComeFromTheRequest()
    {
        $request = $this->createStub(RequestService::class);
        $request->method('analyzeArray')
                ->willReturnCallback(static fn(string $param) => $param === 'items' ? ['1', '2'] : null);

        self::assertSame(['1', '2'], $this->host->itemsIdFromRequest($request));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = new ItemTraitFixture();
    }

    /**
     * @return CustomFieldItem[]
     */
    private function whenReadingTheFieldsOf(Simple $row): array
    {
        $service = $this->createStub(CustomFieldDataService::class);
        $service->method('getBy')->willReturn([$row]);

        return $this->host->readCustomFields(self::MODULE_ID, 1, $service);
    }

    /**
     * The stored row as the repository hands it over, with the definition's own columns joined on.
     * They arrive as they come out of the database, so the casting the trait does is exercised.
     *
     * @param array<string, mixed> $properties
     */
    private function buildRow(array $properties = []): Simple
    {
        return new Simple(
            array_merge(
                [
                    'required' => '1',
                    'showInList' => '0',
                    'help' => 'some help',
                    'definitionId' => '4',
                    'definitionName' => 'A field',
                    'typeId' => '1',
                    'typeName' => 'text',
                    'typeText' => 'Text',
                    'moduleId' => (string)self::MODULE_ID,
                    'isEncrypted' => '0',
                    'data' => null,
                    'key' => null,
                ],
                $properties
            )
        );
    }

    /**
     * The custom fields as posted, keyed by the definition each belongs to.
     *
     * @param array<int, string>|null $fields
     */
    private function givenThePostedFields(?array $fields): RequestService|Stub
    {
        $request = $this->createStub(RequestService::class);
        $request->method('analyzeArray')
                ->willReturnCallback(static fn(string $param) => $param === 'customfield' ? $fields : null);

        return $request;
    }
}

/**
 * The trait is used by controllers, and its methods are protected. This exposes them, so the
 * behaviour can be exercised without standing up a controller and everything it depends on.
 */
final class ItemTraitFixture
{
    use ItemTrait;

    /**
     * @return CustomFieldItem[]
     */
    public function readCustomFields(
        int $moduleId,
        ?int $itemId,
        CustomFieldDataService $service
    ): array {
        return $this->getCustomFieldsForItem($moduleId, $itemId, $service);
    }

    /**
     * @param int|int[] $itemId
     */
    public function addCustomFields(
        int $moduleId,
        int|array $itemId,
        RequestService $request,
        CustomFieldDataService $service
    ): void {
        $this->addCustomFieldsForItem($moduleId, $itemId, $request, $service);
    }

    /**
     * @param int|int[] $itemId
     */
    public function updateCustomFields(
        int $moduleId,
        int|array $itemId,
        RequestService $request,
        CustomFieldDataService $service
    ): void {
        $this->updateCustomFieldsForItem($moduleId, $itemId, $request, $service);
    }

    /**
     * @param int|int[] $itemId
     */
    public function deleteCustomFields(
        int $moduleId,
        int|array $itemId,
        CustomFieldDataService $service
    ): void {
        $this->deleteCustomFieldsForItem($moduleId, $itemId, $service);
    }

    public function searchData(int $limitCount, RequestService $request): ItemSearchDto
    {
        return $this->getSearchData($limitCount, $request);
    }

    /**
     * @return mixed[]|null
     */
    public function itemsIdFromRequest(RequestService $request): ?array
    {
        return $this->getItemsIdFromRequest($request);
    }
}
