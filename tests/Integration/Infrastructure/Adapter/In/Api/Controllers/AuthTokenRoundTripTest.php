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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
     * A token for an action that carries a vault can be created through the API, and works.
     *
     * It could not be. `AuthToken::injectSecureData()` seals the master password into the new
     * token, which needs the master password on the context, and the API only ever loads that from
     * the *calling* token's own vault — which `AUTHTOKEN_CREATE` did not have. So every action a
     * token carries a vault for, `ACCOUNT_VIEW` and `ACCOUNT_CREATE` among them, answered 500
     * "Error while retrieving master password from context", with or without a password, while the
     * web created the same tokens without difficulty.
     *
     * The round trip is the point: minting it is only half of it, so the token it answers with is
     * then used to read an account, custom fields and all, which is the path that needs the vault.
     *
     * @throws \Exception
     */
    #[Test]
    public function aTokenForAnActionThatCarriesAVaultCanBeCreatedAndUsed(): void
    {
        $token = $this->createTokenFor(AclActionsInterface::ACCOUNT_VIEW);

        $r = $this->callApiPath(
            'GET',
            '/api/v1/accounts/1',
            ['customFields' => '1', 'tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $token
        );

        $this->assertSame(200, $r->status, 'the token has to open the account it was issued for');
        $this->assertNotEmpty($r->body->data);
    }

    /**
     * Every one of them, so this is not a fix for the one action that happened to be tried.
     *
     * @throws \Exception
     */
    #[Test]
    #[DataProvider('vaultCarryingActionProvider')]
    public function everyActionThatCarriesAVaultCanBeMinted(int $actionId): void
    {
        $created = $this->callApi(
            AclActionsInterface::AUTHTOKEN_CREATE,
            [
                'userId' => self::ADMIN_USER_ID,
                'actionId' => $actionId,
                'password' => self::AUTH_TOKEN_PASS,
            ]
        );

        $this->assertSame(201, $created->status, json_encode($created->body));
        $this->assertNotEmpty($created->body->data->token);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function vaultCarryingActionProvider(): array
    {
        return [
            'ACCOUNT_VIEW_PASS' => [AclActionsInterface::ACCOUNT_VIEW_PASS],
            'ACCOUNT_EDIT_PASS' => [AclActionsInterface::ACCOUNT_EDIT_PASS],
            'ACCOUNT_CREATE' => [AclActionsInterface::ACCOUNT_CREATE],
            'PUBLICLINK_CREATE' => [AclActionsInterface::PUBLICLINK_CREATE],
            'PUBLICLINK_REFRESH' => [AclActionsInterface::PUBLICLINK_REFRESH],
            'ACCOUNT_VIEW' => [AclActionsInterface::ACCOUNT_VIEW],
            'CATEGORY_VIEW' => [AclActionsInterface::CATEGORY_VIEW],
            'CLIENT_VIEW' => [AclActionsInterface::CLIENT_VIEW],
        ];
    }

    /**
     * Without a password the vault would be sealed with the empty string, and nothing could open
     * it again: `Api` reads `tokenPass` as a required parameter, and required refuses the empty
     * string, so the one password that would work cannot be presented. The web form has refused
     * this since #833; the API refuses it now too, rather than issuing a token that can never be
     * used.
     *
     * @throws \Exception
     */
    #[Test]
    public function aTokenThatCarriesAVaultIsRefusedWithoutAPassword(): void
    {
        $created = $this->callApi(
            AclActionsInterface::AUTHTOKEN_CREATE,
            ['userId' => self::ADMIN_USER_ID, 'actionId' => AclActionsInterface::ACCOUNT_VIEW]
        );

        $this->assertInstanceOf(stdClass::class, $created->body->error ?? null);
        $this->assertSame('Password cannot be blank', $created->body->error->message);
    }

    /**
     * The control: an action that carries no vault still needs no password, so the rule above did
     * not become "every token needs one".
     *
     * @throws \Exception
     */
    #[Test]
    public function aTokenThatCarriesNoVaultStillNeedsNoPassword(): void
    {
        $created = $this->callApi(
            AclActionsInterface::AUTHTOKEN_CREATE,
            ['userId' => self::ADMIN_USER_ID, 'actionId' => AclActionsInterface::CATEGORY_SEARCH]
        );

        $this->assertSame(201, $created->status, json_encode($created->body));
        $this->assertNotEmpty($created->body->data->token);
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
