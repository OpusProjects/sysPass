<?php
declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Domain\Common\Adapters;

use RuntimeException;
use SP\Domain\Common\Adapters\SelectItem;
use SP\Domain\Common\Adapters\SelectItemAdapter;
use SP\Domain\Common\Models\Item;
use SP\Domain\Core\Exceptions\SPException;
use SP\Tests\Support\UnitaryTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Class SelectItemAdapterTest
 *
 * Every dropdown in the application (accounts, categories, tags, users...) is built through this
 * adapter, either straight from a list of domain models or from a raw key/value array. Getting the
 * filtering, the id/name mapping or the selected/skip flags wrong means the wrong option is shown as
 * chosen, an invalid choice (e.g. an item as its own parent) becomes selectable, or a page that mixes
 * trusted models with stray input renders garbage instead of failing loudly.
 */
#[Group('unitary')]
class SelectItemAdapterTest extends UnitaryTestCase
{
    /**
     * Bulk operations (e.g. "select these account ids") build their id list from whatever the
     * caller handed over. An entry that is not an object, or an object without an id, must be
     * dropped rather than turning into a bogus id that reaches a query.
     */
    public function testGetIdFromArrayOfObjectsKeepsOnlyObjectsThatHaveAnId(): void
    {
        $withId = (object)['id' => 5, 'name' => 'has an id'];
        $withoutId = (object)['name' => 'no id here'];

        $items = [
            'skip_no_object' => 'not-an-object',
            'skip_no_id' => $withoutId,
            'keep' => $withId,
        ];

        $result = SelectItemAdapter::getIdFromArrayOfObjects($items);

        self::assertSame(['keep' => 5], $result);
    }

    /**
     * A JSON select list built from models must not include something that is not one, since the
     * front end trusts every entry to have both an id and a name.
     *
     * @throws SPException
     */
    public function testGetJsonItemsFromModelSerialisesOnlyModelInstances(): void
    {
        $items = [
            new Item(['id' => 1, 'name' => 'Alpha']),
            new Item(['id' => 2, 'name' => 'Beta']),
            'not-a-model',
        ];

        $json = SelectItemAdapter::factory($items)->getJsonItemsFromModel();
        $decoded = array_values(json_decode($json, true));

        self::assertSame(
            [['id' => 1, 'name' => 'Alpha'], ['id' => 2, 'name' => 'Beta']],
            $decoded
        );
    }

    /**
     * Simple option lists (e.g. constants) are keyed by their id, with the value as the label. If
     * the key/value pair were not turned into id/name, the select component would have nothing to
     * bind the option's value to.
     *
     * @throws SPException
     */
    public function testGetJsonItemsFromArrayTurnsKeysAndValuesIntoIdAndNamePairs(): void
    {
        $items = ['us' => 'United States', 'es' => 'Spain'];

        $json = SelectItemAdapter::factory($items)->getJsonItemsFromArray();
        $decoded = json_decode($json, true);

        self::assertSame(
            [['id' => 'us', 'name' => 'United States'], ['id' => 'es', 'name' => 'Spain']],
            $decoded
        );
    }

    /**
     * A non-model item reaching the model-only path is an upstream programming error (a raw row
     * that was never turned into a domain model). Failing loudly here is what stops a broken select
     * list from being rendered to the user instead of a clear error.
     */
    public function testGetItemsFromModelThrowsWhenAnItemIsNotADomainModel(): void
    {
        $items = [new Item(['id' => 1, 'name' => 'Alpha']), 'not-a-model'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Wrong object type');

        SelectItemAdapter::factory($items)->getItemsFromModel();
    }

    /**
     * Each model is turned into a SelectItem carrying its own id and name, which is what the select
     * component's value/label pair comes from.
     */
    public function testGetItemsFromModelBuildsSelectItemsCarryingTheirIdAndName(): void
    {
        $items = [new Item(['id' => 7, 'name' => 'Seven'])];

        $result = SelectItemAdapter::factory($items)->getItemsFromModel();

        self::assertCount(1, $result);
        self::assertInstanceOf(SelectItem::class, $result[0]);
        self::assertSame(7, $result[0]->getId());
        self::assertSame('Seven', $result[0]->getName());
    }

    /**
     * Editing an item (e.g. a category) pre-selects the ones it is already linked to, and excludes
     * the item itself from being selectable as its own parent. Mixing these two flags up would show
     * the wrong pre-selection or let an item be assigned to itself.
     */
    public function testGetItemsFromModelSelectedMarksTheMatchingItemsAsSelectedAndTheOwnerAsSkip(): void
    {
        $items = [
            new Item(['id' => 1, 'name' => 'One']),
            new Item(['id' => 2, 'name' => 'Two']),
            new Item(['id' => 3, 'name' => 'Three']),
        ];

        $result = SelectItemAdapter::factory($items)->getItemsFromModelSelected([2, 3], 1);

        self::assertTrue($result[0]->isSkip(), 'the owning item (id 1) is excluded from selection');
        self::assertFalse($result[0]->isSelected());

        self::assertFalse($result[1]->isSkip());
        self::assertTrue($result[1]->isSelected(), 'id 2 was in the selected list');

        self::assertFalse($result[2]->isSkip());
        self::assertTrue($result[2]->isSelected(), 'id 3 was in the selected list');
    }

    /**
     * The plain key/value path builds the same SelectItem shape as the model path, so both kinds of
     * source data can feed the same select component.
     */
    public function testGetItemsFromArrayBuildsSelectItemsFromKeyValuePairs(): void
    {
        $items = ['a' => 'Alpha', 'b' => 'Beta'];

        $result = SelectItemAdapter::factory($items)->getItemsFromArray();

        self::assertCount(2, $result);
        self::assertSame('a', $result[0]->getId());
        self::assertSame('Alpha', $result[0]->getName());
        self::assertSame('b', $result[1]->getId());
        self::assertSame('Beta', $result[1]->getName());
    }

    /**
     * By default the selected list is matched against the option's id (its array key), which is how
     * most option lists are pre-selected from a set of stored ids.
     */
    public function testGetItemsFromArraySelectedMatchesByIdByDefault(): void
    {
        $items = ['a' => 'Alpha', 'b' => 'Beta'];

        $result = SelectItemAdapter::factory($items)->getItemsFromArraySelected(['b']);

        self::assertFalse($result[0]->isSelected(), 'key "a" was not in the selected list');
        self::assertTrue($result[1]->isSelected(), 'key "b" was in the selected list');
    }

    /**
     * When the caller stores the selected option by its label rather than its key (e.g. a language
     * code list keyed by an index but stored by name), matching switches to the value instead of the
     * id — getting this flag backwards would leave every previously-chosen option unselected.
     */
    public function testGetItemsFromArraySelectedCanMatchByValueInstead(): void
    {
        $items = ['a' => 'Alpha', 'b' => 'Beta'];

        $result = SelectItemAdapter::factory($items)->getItemsFromArraySelected(['Alpha'], true);

        self::assertTrue($result[0]->isSelected(), 'value "Alpha" was in the selected list');
        self::assertFalse($result[1]->isSelected(), 'value "Beta" was not in the selected list');
    }
}
