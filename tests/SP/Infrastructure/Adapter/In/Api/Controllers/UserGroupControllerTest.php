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
 * REST API tests for the UserGroup controllers.
 */
#[Group('integration')]
class UserGroupControllerTest extends ApiTestCase
{
    private const PARAMS = [
        'name' => 'API UserGroup',
        'description' => "API test\ndescription",
        'usersId' => [3, 4],
    ];

    public function testCreateAction(): void
    {
        $r = $this->createUserGroup(self::PARAMS);

        $this->assertSame(201, $r->status);
        $this->assertSame(7, $r->body->itemId);
        $this->assertSame('Group added', $r->body->message);
        $this->assertSame($r->body->itemId, $r->body->data->id);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
        $this->assertSame(self::PARAMS['description'], $r->body->data->description);
        $this->assertCount(2, $r->body->data->users);
        $this->assertSame(self::PARAMS['usersId'][0], $r->body->data->users[0]);
    }

    private function createUserGroup(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::GROUP_CREATE, $params ?? self::PARAMS);
    }

    public function testCreateActionInvalidUser(): void
    {
        $params = self::PARAMS;
        $params['usersId'] = [10];

        $r = $this->createUserGroup($params);

        // The FK violation (nonexistent user) surfaces as a missing reference
        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Referenced record not found', $r->body->error->message);
    }

    public function testCreateActionRequiredParameters(): void
    {
        $params = self::PARAMS;
        unset($params['name']);

        $r = $this->createUserGroup($params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testCreateActionDuplicatedName(): void
    {
        $params = self::PARAMS;
        $params['name'] = 'Admins';

        $r = $this->createUserGroup($params);

        // The duplicate name surfaces its specific error, not a generic rollback
        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated group name', $r->body->error->message);
    }

    public function testViewAction(): void
    {
        $id = $this->createUserGroup(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::GROUP_VIEW, ['id' => $id]);

        // UserGroup has no adapter/transformer, so the item is returned directly
        $this->assertSame(200, $r->status);
        $item = $r->body->data;
        $this->assertSame(self::PARAMS['name'], $item->name);
        $this->assertSame(self::PARAMS['description'], $item->description);
        $this->assertCount(2, $item->users);
        $this->assertSame(self::PARAMS['usersId'][0], $item->users[0]);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::GROUP_VIEW, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Group not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createUserGroup(self::PARAMS)->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API test edit',
            'description' => "API test\ndescription",
            'usersId' => [3, 4],
        ];

        $r = $this->callApi(AclActionsInterface::GROUP_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Group updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::GROUP_VIEW, ['id' => $id]);
        $item = $view->body->data;
        $this->assertSame($params['name'], $item->name);
        $this->assertSame($params['description'], $item->description);
    }

    public function testEditActionInvalidUser(): void
    {
        $id = $this->createUserGroup(self::PARAMS)->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API test edit',
            'description' => "API test\ndescription",
            'usersId' => [10],
        ];

        $r = $this->callApi(AclActionsInterface::GROUP_EDIT, $params);

        // The FK violation (nonexistent user) surfaces as a missing reference
        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Referenced record not found', $r->body->error->message);
    }

    public function testEditActionRequiredParameters(): void
    {
        $id = $this->createUserGroup(self::PARAMS)->body->itemId;

        $r = $this->callApi(AclActionsInterface::GROUP_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testEditActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::GROUP_EDIT, ['id' => 10, 'name' => 'API test edit']);

        $this->assertSame(200, $r->status);
        $this->assertSame('Group updated', $r->body->message);
    }

    #[DataProvider('searchProvider')]
    public function testSearchActionByFilter(array $filter, int $resultsCount): void
    {
        $r = $this->callApi(AclActionsInterface::GROUP_SEARCH, $filter);

        $this->assertSame(200, $r->status);
        $this->assertSame($resultsCount, $r->body->count);
        $this->assertCount($resultsCount, $r->body->data);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createUserGroup()->body->itemId;

        $r = $this->callApi(AclActionsInterface::GROUP_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Group deleted', $r->body->message);
        $this->assertSame($id, $r->body->itemId);
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::GROUP_DELETE, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Group not found', $r->body->error->message);
    }

    public function testDeleteActionWithoutId(): void
    {
        $r = $this->callApi(AclActionsInterface::GROUP_DELETE, []);

        $this->assertSame(404, $r->status);
        $this->assertSame('Group not found', $r->body->error->message);
    }

    public static function searchProvider(): array
    {
        return [
            [[], 6],
            [['count' => 1], 1],
            [['text' => 'Demo'], 1],
            [['text' => 'Test'], 3],
            [['text' => 'Grupo'], 1],
        ];
    }
}
