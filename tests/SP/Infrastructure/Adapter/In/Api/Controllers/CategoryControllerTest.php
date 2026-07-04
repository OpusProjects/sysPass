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

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * REST API tests for the Category controllers.
 */
#[Group('integration')]
class CategoryControllerTest extends ApiTestCase
{
    private const PARAMS = [
        'name' => 'API Category',
        'description' => "API test\ndescription",
    ];

    public function testCreateAction(): void
    {
        $r = $this->createCategory(self::PARAMS);

        $this->assertSame(201, $r->status);
        $this->assertSame(4, $r->body->itemId);
        $this->assertSame('Category added', $r->body->message);
        $this->assertSame($r->body->itemId, $r->body->data->id);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
        $this->assertSame(self::PARAMS['description'], $r->body->data->description);
    }

    private function createCategory(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::CATEGORY_CREATE, $params ?? self::PARAMS);
    }

    public function testCreateActionDuplicated(): void
    {
        $r = $this->createCategory(['name' => 'web']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated category', $r->body->error->message);
    }

    public function testCreateActionRequiredParameter(): void
    {
        $params = self::PARAMS;
        unset($params['name']);

        $r = $this->createCategory($params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createCategory(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CATEGORY_VIEW, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);

        $item = $r->body->data->data;
        $this->assertSame(self::PARAMS['name'], $item->name);
        $this->assertSame(self::PARAMS['description'], $item->description);
        $this->assertNull($item->customFields);
        $this->assertIsArray($item->links);
        $this->assertSame('self', $item->links[0]->rel);
        $this->assertNotEmpty($item->links[0]->uri);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::CATEGORY_VIEW, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Category not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createCategory(self::PARAMS)->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API test edit',
            'description' => "API test\ndescription\nedit",
        ];

        $r = $this->callApi(AclActionsInterface::CATEGORY_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Category updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::CATEGORY_VIEW, ['id' => $id]);

        $this->assertSame(1, $view->body->count);
        $item = $view->body->data->data;
        $this->assertSame($params['name'], $item->name);
        $this->assertSame($params['description'], $item->description);
        $this->assertNull($item->customFields);
    }

    public function testEditActionDuplicated(): void
    {
        $id = $this->createCategory(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CATEGORY_EDIT, ['id' => $id, 'name' => 'web']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated category name', $r->body->error->message);
    }

    public function testEditActionRequiredParameters(): void
    {
        $id = $this->createCategory(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CATEGORY_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testEditActionNonExistant(): void
    {
        // Editing a non-existent id is a no-op that still reports success (0 rows)
        $params = [
            'id' => 10,
            'name' => 'API test edit',
            'description' => "API test\ndescription\nedit",
        ];

        $r = $this->callApi(AclActionsInterface::CATEGORY_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Category updated', $r->body->message);
    }

    #[DataProvider('searchProvider')]
    public function testSearchActionByFilter(array $filter, int $resultsCount): void
    {
        $r = $this->callApi(AclActionsInterface::CATEGORY_SEARCH, $filter);

        $this->assertSame(200, $r->status);
        $this->assertSame($resultsCount, $r->body->count);
        $this->assertCount($resultsCount, $r->body->data);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createCategory()->body->itemId;

        $r = $this->callApi(AclActionsInterface::CATEGORY_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Category deleted', $r->body->message);
        $this->assertSame($id, $r->body->itemId);
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::CATEGORY_DELETE, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Category not found', $r->body->error->message);
    }

    public function testDeleteActionWithoutId(): void
    {
        // In REST the id is a path segment; a missing id resolves to 0 → not found
        $r = $this->callApi(AclActionsInterface::CATEGORY_DELETE, []);

        $this->assertSame(404, $r->status);
        $this->assertSame('Category not found', $r->body->error->message);
    }

    public static function searchProvider(): array
    {
        return [
            [[], 3],
            [['count' => 1], 1],
            [['text' => 'Linux'], 1],
            [['text' => 'Windows'], 0],
        ];
    }
}
