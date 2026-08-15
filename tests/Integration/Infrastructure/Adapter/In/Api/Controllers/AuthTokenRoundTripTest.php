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

/**
 * A token is how the API is reached at all, and every other test in this tree mints one by calling
 * AuthTokenService directly inside the harness. Nothing used a token that came back from the create
 * endpoint, so a create answering with a stale, truncated or wrongly encoded token would have been
 * green everywhere — and the failure would have landed on whoever first tried to automate against
 * this API, with no way to tell whether the fault was theirs.
 *
 * One harness detail makes this expressible: callApi() truncates the AuthToken table on every call,
 * so a token created through it survives until the *next* callApi() — while callApiPath(), which
 * takes the Authorization header verbatim, never truncates. Creating with the first and using the
 * second is the whole trick.
 */
#[Group('integration')]
class AuthTokenRoundTripTest extends ApiTestCase
{
    private const ADMIN_USER_ID = 1;

    /**
     * The round trip: create a token for an action through the real endpoint, then use exactly the
     * string it answered with to make a real call to that action, and require it to be served.
     */
    public function testATokenFromTheCreateEndpointAuthenticatesItsOwnAction(): void
    {
        $token = $this->createTokenFor(AclActionsInterface::CATEGORY_SEARCH);

        $r = $this->callApiPath('GET', '/api/v1/categories', [], 'Bearer ' . $token);

        $this->assertSame(200, $r->status, 'a token straight from the create endpoint has to work');
        $this->assertIsArray($r->body->data);
    }

    /**
     * And it opens only what it was issued for. The token lookup is scoped by action, so a token
     * minted for one endpoint is simply not found for another — which is what makes "issued for an
     * action" mean anything at all. Without this, the test above would be satisfied by a token that
     * opened everything.
     */
    public function testATokenDoesNotAuthoriseAnActionItWasNotIssuedFor(): void
    {
        $token = $this->createTokenFor(AclActionsInterface::CATEGORY_SEARCH);

        $r = $this->callApiPath('GET', '/api/v1/clients', [], 'Bearer ' . $token);

        $this->assertGreaterThanOrEqual(400, $r->status, 'a category token must not list clients');
        $this->assertObjectHasProperty('error', $r->body);
    }

    /**
     * A token nobody issued is refused, so the two above cannot be passing on some path that
     * accepts anything shaped like a token.
     */
    public function testATokenThatWasNeverIssuedIsRefused(): void
    {
        $r = $this->callApiPath('GET', '/api/v1/categories', [], 'Bearer ' . bin2hex(random_bytes(32)));

        $this->assertGreaterThanOrEqual(400, $r->status);
        $this->assertObjectHasProperty('error', $r->body);
    }

    /**
     * Create a token through the API and hand back the bearer string it answered with.
     *
     * @throws \Exception
     */
    private function createTokenFor(int $actionId): string
    {
        $created = $this->callApi(
            AclActionsInterface::AUTHTOKEN_CREATE,
            [
                'userId' => self::ADMIN_USER_ID,
                'actionId' => $actionId,
                'password' => self::AUTH_TOKEN_PASS,
            ]
        );

        $this->assertSame(201, $created->status);
        $this->assertInstanceOf(stdClass::class, $created->body->data);
        $this->assertNotEmpty($created->body->data->token, 'create has to answer with the token itself');

        return $created->body->data->token;
    }
}
