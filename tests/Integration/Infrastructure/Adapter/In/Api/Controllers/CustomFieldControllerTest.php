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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * Covers the custom-field definition endpoints over the REST API. None of them had tests.
 *
 * A definition decides what is collected on an account and whether it is stored encrypted, so
 * these run against the real database rather than a stubbed one.
 */
#[Group('integration')]
class CustomFieldControllerTest extends ApiTestCase
{
    private const PARAMS = [
        'name' => 'API custom field',
        'typeId' => 1,
        'moduleId' => 10,
        'help' => 'Some help',
    ];

    public function testCreateAction(): void
    {
        $r = $this->createField();

        $this->assertSame(201, $r->status);
        $this->assertSame('Custom field added', $r->body->message);
        $this->assertGreaterThan(0, $r->body->itemId);
    }

    /**
     * The name, type and module are all required: a definition missing any of them describes
     * nothing the UI could render.
     */
    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->callApi(AclActionsInterface::CUSTOMFIELD_CREATE, []);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('name', $r->body->error->detail);
        $this->assertStringContainsString('typeId', $r->body->error->detail);
        $this->assertStringContainsString('moduleId', $r->body->error->detail);
    }

    public function testViewAction(): void
    {
        $id = $this->createField()->body->itemId;

        $r = $this->callApi(AclActionsInterface::CUSTOMFIELD_VIEW, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame(self::PARAMS['name'], $r->body->data->name);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::CUSTOMFIELD_VIEW, ['id' => 10000]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
    }

    public function testEditAction(): void
    {
        $id = $this->createField()->body->itemId;

        $r = $this->callApi(
            AclActionsInterface::CUSTOMFIELD_EDIT,
            ['id' => $id, 'name' => 'API custom field edited', 'typeId' => 1, 'moduleId' => 10]
        );

        $this->assertSame(200, $r->status);
        $this->assertSame('Custom field updated', $r->body->message);

        $view = $this->callApi(AclActionsInterface::CUSTOMFIELD_VIEW, ['id' => $id]);

        $this->assertSame('API custom field edited', $view->body->data->name);
    }

    public function testDeleteAction(): void
    {
        $id = $this->createField()->body->itemId;

        $r = $this->callApi(AclActionsInterface::CUSTOMFIELD_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Custom field removed', $r->body->message);

        $view = $this->callApi(AclActionsInterface::CUSTOMFIELD_VIEW, ['id' => $id]);

        $this->assertInstanceOf(stdClass::class, $view->body->error, 'a removed definition is gone');
    }

    public function testSearchAction(): void
    {
        $this->createField();

        $r = $this->callApi(AclActionsInterface::CUSTOMFIELD_SEARCH, ['text' => 'API custom field']);

        $this->assertSame(200, $r->status);
        $this->assertGreaterThan(0, $r->body->count);
    }

    private function createField(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::CUSTOMFIELD_CREATE, $params ?? self::PARAMS);
    }
}
