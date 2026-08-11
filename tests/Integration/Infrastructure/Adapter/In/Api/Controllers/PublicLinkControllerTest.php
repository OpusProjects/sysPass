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
 * Covers the public-link endpoints over the REST API. None of them had tests.
 *
 * A public link hands an account's password to whoever holds the URL, so creating, re-keying and
 * revoking one over the API is the surface worth pinning down. These run against the real
 * database.
 */
#[Group('integration')]
class PublicLinkControllerTest extends ApiTestCase
{
    /**
     * An account from the seeded fixture that does not already have a link — accounts 1 and 2
     * do, and a second link for the same account is refused.
     */
    private const ACCOUNT_ID = 3;

    public function testCreateAction(): void
    {
        $r = $this->createLink();

        $this->assertSame(201, $r->status);
        $this->assertGreaterThan(0, $r->body->itemId);
    }

    /**
     * A link with no account behind it would hand out nothing, so the account is required.
     */
    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->callApi(AclActionsInterface::PUBLICLINK_CREATE, []);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
    }

    public function testViewAction(): void
    {
        $id = $this->createLink()->body->itemId;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame(self::ACCOUNT_ID, $r->body->data->itemId);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => 10000]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
    }

    /**
     * Refreshing re-keys the link, so the URL handed out previously stops working. The hash is
     * asserted to change, since that is the whole point of the operation.
     */
    public function testRefreshActionChangesTheHash(): void
    {
        $id = $this->createLink()->body->itemId;

        $before = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id])->body->data->hash;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_REFRESH, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Public link refreshed', $r->body->message);

        $after = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id])->body->data->hash;

        $this->assertNotSame($before, $after, 'a refreshed link must not keep its old URL');
    }

    public function testDeleteAction(): void
    {
        $id = $this->createLink()->body->itemId;

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Public link removed', $r->body->message);

        $view = $this->callApi(AclActionsInterface::PUBLICLINK_VIEW, ['id' => $id]);

        $this->assertInstanceOf(stdClass::class, $view->body->error, 'a revoked link is gone');
    }

    public function testSearchAction(): void
    {
        $this->createLink();

        $r = $this->callApi(AclActionsInterface::PUBLICLINK_SEARCH, []);

        $this->assertSame(200, $r->status);
        $this->assertGreaterThan(0, $r->body->count);
    }

    private function createLink(): stdClass
    {
        return $this->callApi(AclActionsInterface::PUBLICLINK_CREATE, ['itemId' => self::ACCOUNT_ID]);
    }
}
