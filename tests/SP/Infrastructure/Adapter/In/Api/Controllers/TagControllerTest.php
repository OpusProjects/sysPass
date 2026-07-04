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
 * REST API tests for the Tag controllers.
 */
#[Group('integration')]
class TagControllerTest extends ApiTestCase
{
    private const PARAMS = ['name' => 'API Tag'];

    public function testCreateAction(): void
    {
        $r = $this->createTag(self::PARAMS);

        $this->assertSame(201, $r->status);
        $this->assertSame(4, $r->body->itemId);
        $this->assertSame('Tag added', $r->body->message);
        $this->assertSame($r->body->itemId, $r->body->data->id);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
    }

    private function createTag(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::TAG_CREATE, $params ?? self::PARAMS);
    }

    public function testCreateActionDuplicated(): void
    {
        $r = $this->createTag(['name' => 'linux']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated tag', $r->body->error->message);
    }

    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->createTag([]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createTag(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::TAG_VIEW, ['id' => $id]);

        // Tag has no adapter/transformer, so the item is returned directly
        $this->assertSame(200, $r->status);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::TAG_VIEW, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Tag not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createTag(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::TAG_EDIT, ['id' => $id, 'name' => 'API test edit']);

        $this->assertSame(200, $r->status);
        $this->assertSame('Tag updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::TAG_VIEW, ['id' => $id]);
        $this->assertSame('API test edit', $view->body->data->name);
    }

    public function testEditActionDuplicated(): void
    {
        $id = $this->createTag(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::TAG_EDIT, ['id' => $id, 'name' => 'linux']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated tag', $r->body->error->message);
    }

    public function testEditActionWrongParameters(): void
    {
        $id = $this->createTag(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::TAG_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testEditActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::TAG_EDIT, ['id' => 10, 'name' => 'API test edit']);

        $this->assertSame(200, $r->status);
        $this->assertSame('Tag updated', $r->body->message);
    }

    #[DataProvider('searchProvider')]
    public function testSearchActionByFilter(array $filter, int $resultsCount): void
    {
        $r = $this->callApi(AclActionsInterface::TAG_SEARCH, $filter);

        $this->assertSame(200, $r->status);
        $this->assertSame($resultsCount, $r->body->count);
        $this->assertCount($resultsCount, $r->body->data);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createTag()->body->itemId;

        $r = $this->callApi(AclActionsInterface::TAG_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Tag removed', $r->body->message);
        $this->assertSame($id, $r->body->itemId);
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::TAG_DELETE, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Tag not found', $r->body->error->message);
    }

    public function testDeleteActionWithoutId(): void
    {
        $r = $this->callApi(AclActionsInterface::TAG_DELETE, []);

        $this->assertSame(404, $r->status);
        $this->assertSame('Tag not found', $r->body->error->message);
    }

    public static function searchProvider(): array
    {
        return [
            [[], 3],
            [['count' => 1], 1],
            [['text' => 'Linux'], 1],
            [['text' => 'Google'], 0],
        ];
    }
}
