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

use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

use function SP\Tests\getDbHandler;

/**
 * Covers the auth-token endpoints over the REST API — the tokens that decide whether a caller may
 * use the API at all. None of them had tests.
 *
 * ApiTestCase::createToken() truncates the AuthToken table and inserts a fresh row to authenticate
 * the caller on every single callApi() call, so a token created through one call is already gone
 * by the time a second call could look it up by id — the fixture's own tokens included. That rules
 * out the usual create-then-view-by-id flow the other API test classes use, and it means the only
 * row guaranteed to exist when a request runs is the one callApi() itself just inserted: after the
 * truncate it always lands on id 1, owned by the admin user, for the action under test. Several
 * tests below act on that row ("id" => 1) for exactly that reason — not because the token is
 * special, but because it is the one row every request can rely on.
 */
#[Group('integration')]
class AuthTokenControllerTest extends ApiTestCase
{
    private const ADMIN_USER_ID = 1;

    /**
     * What the create response actually contains — and, just as importantly, what it does not.
     * It carries "userId"/"actionId"/"id" (the request echoed back with the new id attached), but
     * The token itself: a caller that creates one and cannot read it has created nothing, so the
     * answer carries the row that was stored rather than the request that was sent.
     */
    public function testCreateAction(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_CREATE, [
            'userId' => self::ADMIN_USER_ID,
            'actionId' => AclActionsInterface::CATEGORY_SEARCH,
            'password' => 'a token password',
        ]);

        $this->assertSame(201, $r->status);
        $this->assertSame('Authorization added', $r->body->message);
        $this->assertGreaterThan(0, $r->body->itemId);
        $this->assertSame($r->body->itemId, $r->body->data->id);
        $this->assertSame(self::ADMIN_USER_ID, $r->body->data->userId);
        $this->assertSame(AclActionsInterface::CATEGORY_SEARCH, $r->body->data->actionId);

        // The point of the call: the bearer token the service generated comes back, so the caller
        // can actually use what they just created.
        $this->assertNotEmpty($r->body->data->token);
    }

    /**
     * A listing is not a way to read everybody's tokens. The permission to search authorisations
     * is coarser than the permission to view one, and a caller holding only the first would
     * otherwise harvest every bearer token in the installation in a single call. The web grid
     * never showed them either — it lists the user and the action.
     */
    public function testSearchActionDoesNotListTheTokensThemselves(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_SEARCH, []);

        $this->assertSame(200, $r->status);
        $this->assertNotEmpty($r->body->data);

        foreach ($r->body->data as $row) {
            $this->assertObjectNotHasProperty('token', $row, 'a listing must not carry bearer tokens');
            $this->assertNotEmpty($row->userLogin, 'while still saying whose authorisation it is');
        }
    }

    /**
     * userId and actionId are the only parameters the controller actually enforces — "password" is
     * read with getParamRaw('password') and no required flag, so a token can legitimately be
     * created with a null hash.
     */
    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_CREATE, []);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString('userId', $r->body->error->detail);
        $this->assertStringContainsString('actionId', $r->body->error->detail);
    }

    /**
     * callApi() authenticates this very request with a freshly truncated-then-inserted row for
     * (admin, actionId under test) — id 1. Posting that same (userId, actionId) pair back at the
     * create endpoint runs into the same uniqueness check a normal duplicate token would.
     */
    public function testCreateActionSameUserAndActionTwice(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_CREATE, [
            'userId' => self::ADMIN_USER_ID,
            'actionId' => AclActionsInterface::AUTHTOKEN_CREATE,
            'password' => 'duplicate',
        ]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Authorization already exist', $r->body->error->message);
    }

    /**
     * Unlike create (see testCreateAction above), view does hand back the real bearer token — this
     * is how a caller who lost track of a token retrieves it, given the id.
     */
    public function testViewAction(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_VIEW, ['id' => 1]);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->data->id);
        $this->assertSame(self::ADMIN_USER_ID, $r->body->data->userId);
        $this->assertSame(AclActionsInterface::AUTHTOKEN_VIEW, $r->body->data->actionId);
        $this->assertNotEmpty($r->body->data->token);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_VIEW, ['id' => 99999]);

        $this->assertSame(404, $r->status);
        $this->assertSame('Token not found', $r->body->error->message);
    }

    /**
     * Edits the id-1 row callApi() itself just created to authenticate the request, and confirms
     * the change actually reaches the database — not just the response echo.
     */
    public function testEditAction(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_EDIT, [
            'id' => 1,
            'userId' => 5,
            'actionId' => AclActionsInterface::AUTHTOKEN_EDIT,
            'password' => 'edited',
        ]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Authorization updated', $r->body->message);

        $statement = getDbHandler()->getConnection()->prepare('SELECT userId FROM `AuthToken` WHERE id = 1');
        $statement->execute();

        $this->assertSame(5, (int)$statement->fetchColumn());
    }

    public function testDeleteAction(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_DELETE, ['id' => 1]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Authorization removed', $r->body->message);

        $statement = getDbHandler()->getConnection()->prepare('SELECT COUNT(*) FROM `AuthToken` WHERE id = 1');
        $statement->execute();

        $this->assertSame(0, (int)$statement->fetchColumn());
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_DELETE, ['id' => 99999]);

        $this->assertSame(404, $r->status);
        $this->assertSame('Token not found', $r->body->error->message);
    }

    public function testSearchAction(): void
    {
        $r = $this->callApi(AclActionsInterface::AUTHTOKEN_SEARCH, []);

        $this->assertSame(200, $r->status);
        $this->assertCount(1, $r->body->data);
        $this->assertSame('admin', $r->body->data[0]->userLogin);
    }
}
