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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * REST API tests for the Client controllers.
 */
#[Group('integration')]
class ClientControllerTest extends ApiTestCase
{
    private const PARAMS = [
        'name' => 'API Client',
        'description' => "API test\ndescription",
    ];

    public function testCreateAction(): void
    {
        $r = $this->createClient(self::PARAMS);

        $this->assertSame(201, $r->status);
        $this->assertSame(5, $r->body->itemId);
        $this->assertSame('Client added', $r->body->message);
        $this->assertSame($r->body->itemId, $r->body->data->id);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
        $this->assertSame(self::PARAMS['description'], $r->body->data->description);
    }

    private function createClient(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::CLIENT_CREATE, $params ?? self::PARAMS);
    }

    public function testCreateActionDuplicated(): void
    {
        $r = $this->createClient(['name' => 'Google']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated client', $r->body->error->message);
    }

    public function testCreateActionRequiredParameter(): void
    {
        $params = self::PARAMS;
        unset($params['name']);

        $r = $this->createClient($params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createClient(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CLIENT_VIEW, ['id' => $id]);

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
        $r = $this->callApi(AclActionsInterface::CLIENT_VIEW, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Client not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createClient(self::PARAMS)->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API Client edit',
            'description' => "API test\ndescription\nedit",
        ];

        $r = $this->callApi(AclActionsInterface::CLIENT_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Client updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::CLIENT_VIEW, ['id' => $id]);
        $item = $view->body->data->data;
        $this->assertSame($params['name'], $item->name);
        $this->assertSame($params['description'], $item->description);
    }

    public function testEditActionDuplicated(): void
    {
        $id = $this->createClient(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CLIENT_EDIT, ['id' => $id, 'name' => 'Google']);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated client', $r->body->error->message);
    }

    public function testEditActionRequiredParameters(): void
    {
        $id = $this->createClient(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::CLIENT_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testEditActionNonExistant(): void
    {
        $params = [
            'id' => 10,
            'name' => 'API Client edit',
            'description' => "API test\ndescription\nedit",
        ];

        $r = $this->callApi(AclActionsInterface::CLIENT_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Client updated', $r->body->message);
    }

    #[DataProvider('searchProvider')]
    public function testSearchActionByFilter(array $filter, int $resultsCount): void
    {
        $r = $this->callApi(AclActionsInterface::CLIENT_SEARCH, $filter);

        $this->assertSame(200, $r->status);
        $this->assertSame($resultsCount, $r->body->count);
        $this->assertCount($resultsCount, $r->body->data);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createClient()->body->itemId;

        $r = $this->callApi(AclActionsInterface::CLIENT_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Client deleted', $r->body->message);
        $this->assertSame($id, $r->body->itemId);
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::CLIENT_DELETE, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Client not found', $r->body->error->message);
    }

    public function testDeleteActionWithoutId(): void
    {
        $r = $this->callApi(AclActionsInterface::CLIENT_DELETE, []);

        $this->assertSame(404, $r->status);
        $this->assertSame('Client not found', $r->body->error->message);
    }

    public static function searchProvider(): array
    {
        return [
            [[], 4],
            [['count' => 1], 1],
            [['text' => 'Google'], 1],
            [['text' => 'Inc'], 3],
            [['text' => 'Spotify'], 0],
        ];
    }
}
