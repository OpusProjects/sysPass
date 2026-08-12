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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api;

use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\AclActionsInterface;

/**
 * The REST API is a public entry point, and the controller tests only ever reach it down a route
 * that matches, with a token that is right. What it does with everything else — a path it does not
 * serve, a method that path does not take, a token that is missing or wrong — was never exercised,
 * and those are the requests an unauthenticated caller can make.
 */
#[Group('integration')]
class ApiRoutingTest extends ApiTestCase
{
    /**
     * A path the API does not serve is refused rather than falling through to whatever the catch-all
     * would otherwise do, and points at the documentation.
     *
     * @throws Exception
     */
    #[Test]
    public function anUnknownPathIsNotFound()
    {
        $response = $this->callApiPath('GET', '/api/v1/does-not-exist');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('/api/docs', $response->body->error->message);
    }

    /**
     * The item routes only take a numeric id. A path with anything else in that position matches no
     * route at all, so the id never reaches a controller to be used as one.
     *
     * @throws Exception
     */
    #[Test]
    public function aNonNumericIdMatchesNoRoute()
    {
        $response = $this->callApiPath('GET', '/api/v1/accounts/not-a-number');

        self::assertSame(404, $response->status);
    }

    /**
     * A method the path does not take is not silently treated as one that it does — deleting the
     * whole collection is not the same request as deleting one account.
     *
     * @throws Exception
     */
    #[Test]
    public function aMethodThePathDoesNotTakeIsNotFound()
    {
        $response = $this->callApiPath('DELETE', '/api/v1/accounts');

        self::assertSame(404, $response->status);
    }

    /**
     * Nothing is served without a token. This is the check that makes every other one meaningful.
     *
     * @throws Exception
     */
    #[Test]
    public function aRequestWithoutATokenIsRefused()
    {
        $response = $this->callApiPath('GET', '/api/v1/accounts');

        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertNotEmpty($response->body->error->message);
    }

    /**
     * Nor with one that was never issued.
     *
     * @throws Exception
     */
    #[Test]
    public function aRequestWithAnUnknownTokenIsRefused()
    {
        $response = $this->callApiPath(
            'GET',
            '/api/v1/accounts',
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . str_repeat('0', 100)
        );

        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertNotEmpty($response->body->error->message);
    }

    /**
     * The token pass is the second half of the credential for the actions that reach encrypted
     * data: it unwraps the master password out of the token's vault. A real token for such an
     * action, with the wrong pass beside it, gets nothing.
     *
     * @throws Exception
     */
    #[Test]
    public function aSecuredActionWithTheWrongTokenPassIsRefused()
    {
        $token = $this->issueToken(AclActionsInterface::ACCOUNT_VIEW_PASS);

        $response = $this->callApiPath(
            'POST',
            '/api/v1/accounts/1/password',
            ['tokenPass' => 'not-the-pass'],
            'Bearer ' . $token
        );

        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertNotEmpty($response->body->error->message);
    }

    /**
     * The same request with the right pass is served, so the refusal above is the pass being
     * checked and not the endpoint being broken.
     *
     * @throws Exception
     */
    #[Test]
    public function aSecuredActionWithTheRightTokenPassIsServed()
    {
        $token = $this->issueToken(AclActionsInterface::ACCOUNT_VIEW_PASS);

        $response = $this->callApiPath(
            'POST',
            '/api/v1/accounts/1/password',
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $token
        );

        self::assertSame(200, $response->status);
        self::assertNotEmpty($response->body->data->password);
    }

    /**
     * An action that does not touch encrypted data does not ask for the pass at all — the token on
     * its own is the credential there. Pinned so the boundary is explicit rather than looking like
     * the check above simply not firing.
     *
     * @throws Exception
     */
    #[Test]
    public function anUnsecuredActionDoesNotAskForTheTokenPass()
    {
        $token = $this->issueToken(AclActionsInterface::ACCOUNT_SEARCH);

        $response = $this->callApiPath('GET', '/api/v1/accounts', [], 'Bearer ' . $token);

        self::assertSame(200, $response->status);
    }

    /**
     * The same token used against an action it was not issued for is refused. A token is scoped to
     * one action, so holding one for a search must not be enough to delete.
     *
     * @throws Exception
     */
    #[Test]
    public function aTokenIsNotAcceptedForAnotherAction()
    {
        $token = $this->issueToken(AclActionsInterface::ACCOUNT_SEARCH);

        $response = $this->callApiPath(
            'DELETE',
            '/api/v1/accounts/1',
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $token
        );

        self::assertGreaterThanOrEqual(400, $response->status);
        self::assertNotEmpty($response->body->error->message);
    }

    /**
     * The control: the same path, method and token that the refusals above vary from is served.
     * Without it, every assertion here would be satisfied by an API that refused everything.
     *
     * @throws Exception
     */
    #[Test]
    public function theSameRequestDoneRightIsServed()
    {
        $token = $this->issueToken(AclActionsInterface::ACCOUNT_SEARCH);

        $response = $this->callApiPath(
            'GET',
            '/api/v1/accounts',
            ['tokenPass' => self::AUTH_TOKEN_PASS],
            'Bearer ' . $token
        );

        self::assertSame(200, $response->status);
        self::assertObjectNotHasProperty('error', $response->body);
    }
}
