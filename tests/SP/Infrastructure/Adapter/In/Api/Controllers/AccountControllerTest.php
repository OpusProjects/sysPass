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
 * REST API tests for the Account controllers (secured actions with password crypto).
 */
#[Group('integration')]
class AccountControllerTest extends ApiTestCase
{
    private const PARAMS = [
        'name' => 'API test',
        'categoryId' => 2,
        'clientId' => 2,
        'login' => 'root',
        'pass' => 'password_test',
        'expireDate' => 1634395912,
        'url' => 'http://syspass.org',
        'notes' => "test\n\ntest",
        'private' => 0,
        'privateGroup' => 0,
        'userId' => 2,
        'userGroupId' => 2,
        'parentId' => 0,
        'tagsId' => [3],
    ];

    private function createAccount(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::ACCOUNT_CREATE, $params ?? self::PARAMS);
    }

    public function testCreateAction(): void
    {
        $params = self::PARAMS;
        $params['private'] = 1;
        $params['privateGroup'] = 1;
        $params['parentId'] = 1;

        $r = $this->createAccount($params);

        $this->assertSame(201, $r->status);
        $this->assertSame(5, $r->body->itemId);
        $this->assertSame('Account created', $r->body->message);

        $item = $r->body->data;
        $this->assertSame($r->body->itemId, $item->id);
        $this->assertSame(self::PARAMS['name'], $item->name);
        $this->assertSame(self::PARAMS['categoryId'], $item->categoryId);
        $this->assertSame(self::PARAMS['clientId'], $item->clientId);
        $this->assertSame(self::PARAMS['login'], $item->login);
        $this->assertSame(self::PARAMS['expireDate'], $item->passDateChange);
        $this->assertSame(self::PARAMS['url'], $item->url);
        $this->assertSame(self::PARAMS['notes'], $item->notes);
        $this->assertSame(self::PARAMS['userId'], $item->userId);
        $this->assertSame(self::PARAMS['userGroupId'], $item->userGroupId);
        $this->assertSame(1, $item->isPrivate);
        $this->assertSame(1, $item->isPrivateGroup);
        $this->assertSame(1, $item->parentId);
        $this->assertNull($item->dateEdit);
        $this->assertSame(0, $item->countView);
        $this->assertSame(0, $item->countDecrypt);
        $this->assertGreaterThan(0, $item->passDate);
    }

    public function testCreateActionNoUserData(): void
    {
        $params = self::PARAMS;
        unset($params['userId'], $params['userGroupId']);

        $r = $this->createAccount($params);

        $this->assertSame(201, $r->status);
        $this->assertSame(5, $r->body->itemId);
        $this->assertSame('Account created', $r->body->message);
        // Defaults to the authenticated admin user (id 1)
        $this->assertSame(1, $r->body->data->userId);
        $this->assertSame(1, $r->body->data->userGroupId);
    }

    #[DataProvider('getUnsetParams')]
    public function testCreateActionRequiredParameters(string $unsetParam): void
    {
        $params = self::PARAMS;
        unset($params[$unsetParam]);

        $r = $this->createAccount($params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testViewPassAction(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW_PASS, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame(self::PARAMS['pass'], $r->body->data->password);
    }

    public function testViewPassActionRequiredParamater(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW_PASS, []);

        $this->assertSame(404, $r->status);
        $this->assertSame('Account not found', $r->body->error->message);
    }

    public function testViewPassActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW_PASS, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Account not found', $r->body->error->message);
    }

    public function testEditPassAction(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_EDIT_PASS, [
            'id' => $id,
            'pass' => 'test_123',
            'expireDate' => time() + 86400,
        ]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Password updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::ACCOUNT_VIEW_PASS, ['id' => $id]);
        $this->assertSame('test_123', $view->body->data->password);
    }

    public function testEditPassActionRequiredParameters(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_EDIT_PASS, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);

        $item = $r->body->data->data;
        $this->assertSame($id, $item->id);
        $this->assertSame(self::PARAMS['name'], $item->name);
        $this->assertSame(self::PARAMS['categoryId'], $item->categoryId);
        $this->assertSame(self::PARAMS['clientId'], $item->clientId);
        $this->assertSame(self::PARAMS['login'], $item->login);
        $this->assertSame(self::PARAMS['url'], $item->url);
        $this->assertSame(self::PARAMS['notes'], $item->notes);
        $this->assertSame(self::PARAMS['userId'], $item->userId);
        $this->assertSame(self::PARAMS['userGroupId'], $item->userGroupId);
        $this->assertSame(self::PARAMS['expireDate'], $item->passDateChange);
        $this->assertSame(0, $item->countView);
        $this->assertSame(0, $item->countDecrypt);
        $this->assertNull($item->dateEdit);
        $this->assertNull($item->publicLinkHash);
        $this->assertGreaterThan(0, $item->passDate);
        $this->assertIsArray($item->tags);
        $this->assertIsArray($item->users);
        $this->assertIsArray($item->userGroups);
        $this->assertNull($item->customFields);
        $this->assertIsArray($item->links);
        $this->assertSame('self', $item->links[0]->rel);
        $this->assertNotEmpty($item->links[0]->uri);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame("The account doesn't exist", $r->body->error->message);
    }

    public function testViewActionWithoutId(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_VIEW, []);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame("The account doesn't exist", $r->body->error->message);
    }

    #[DataProvider('searchProvider')]
    public function testSearchActionByFilter(array $filter, int $resultsCount): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_SEARCH, $filter);

        $this->assertSame(200, $r->status);
        $this->assertSame($resultsCount, $r->body->count);
        $this->assertCount($resultsCount, $r->body->data);
    }

    public function testEditAction(): void
    {
        $id = $this->createAccount()->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API test edit',
            'categoryId' => 3,
            'clientId' => 3,
            'login' => 'admin',
            'expireDate' => time() + 86400,
            'url' => 'http://demo.syspass.org',
            'notes' => "test\n\ntest\nedit",
            'private' => 0,
            'privateGroup' => 0,
            'userId' => 1,
            'userGroupId' => 1,
            'parentId' => 1,
            'tagsId' => [1],
        ];

        $r = $this->callApi(AclActionsInterface::ACCOUNT_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Account updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::ACCOUNT_VIEW, ['id' => $id]);
        $item = $view->body->data->data;
        $this->assertSame($params['name'], $item->name);
        $this->assertSame($params['categoryId'], $item->categoryId);
        $this->assertSame($params['clientId'], $item->clientId);
        $this->assertSame($params['login'], $item->login);
        $this->assertSame($params['url'], $item->url);
        $this->assertSame($params['notes'], $item->notes);
        $this->assertGreaterThan(0, $item->dateEdit);
    }

    public function testEditActionRequiredParameter(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('help', $r->body->error->detail);
    }

    public function testEditActionNonExistant(): void
    {
        $params = [
            'id' => 10,
            'name' => 'API test edit',
            'categoryId' => 3,
            'clientId' => 3,
            'login' => 'admin',
            'expireDate' => time() + 86400,
            'url' => 'http://demo.syspass.org',
            'notes' => "test\n\ntest\nedit",
            'private' => 0,
            'privateGroup' => 0,
            'userId' => 1,
            'userGroupId' => 1,
            'parentId' => 1,
            'tagsId' => [1],
        ];

        $r = $this->callApi(AclActionsInterface::ACCOUNT_EDIT, $params);

        // The missing account surfaces its specific error, not a generic rollback
        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame("The account doesn't exist", $r->body->error->message);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createAccount()->body->itemId;

        $r = $this->callApi(AclActionsInterface::ACCOUNT_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Account removed', $r->body->message);
        $this->assertSame($id, $r->body->itemId);
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_DELETE, ['id' => 10]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame("The account doesn't exist", $r->body->error->message);
    }

    public function testDeleteActionWithoutId(): void
    {
        $r = $this->callApi(AclActionsInterface::ACCOUNT_DELETE, []);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame("The account doesn't exist", $r->body->error->message);
    }

    public static function searchProvider(): array
    {
        return [
            [[], 2],
            [['count' => 1], 1],
            [['text' => 'Google'], 1],
            [['text' => 'admin'], 2],
            [['text' => 'aaa'], 1],
            [['clientId' => 2], 1],
            [['clientId' => 3], 0],
            [['categoryId' => 1], 1],
            [['categoryId' => 2], 1],
            [['categoryId' => 10], 0],
            [['tagsId' => [3]], 1],
            [['tagsId' => [1, 3]], 1],
            [['tagsId' => [1, 3], 'op' => 'or'], 2],
            [['tagsId' => [1, 4]], 0],
            [['tagsId' => [10]], 0],
            [['categoryId' => 1, 'clientId' => 1], 1],
            [['categoryId' => 2, 'clientId' => 1], 0],
            // op=or ORs every dimension: category 2 (Apple) OR client 1 (Google) → both
            [['categoryId' => 2, 'clientId' => 1, 'op' => 'or'], 2],
            // op=or across a dimension and tags: category 1 (Google) OR tag 3 (Apple) → both
            [['categoryId' => 1, 'tagsId' => [3], 'op' => 'or'], 2],
        ];
    }

    public static function getUnsetParams(): array
    {
        return [['name'], ['clientId'], ['categoryId']];
    }
}
