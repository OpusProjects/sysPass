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
 * REST API tests for the User controllers.
 *
 * The users endpoint is the one that reads rows carrying the credential material, so the first
 * thing asserted here is what does NOT come back: the password hash and its salt, and the user's
 * master password and the key it is sealed with. The rest is the ordinary shape of the endpoint —
 * what it creates, what it refuses, and who it will not let a caller delete.
 */
#[Group('integration')]
class UserControllerTest extends ApiTestCase
{
    /** The fixture's administrator, the only user whose row holds a master password. */
    private const ADMIN_ID = 1;
    /** A fixture user who owns nothing, so removing them is only about the endpoint. */
    private const DELETABLE_ID = 5;

    /**
     * The one that matters. A token holding USER_VIEW is not a token holding the vault: the row
     * behind this endpoint carries the bcrypt password hash, the salt it was made with, and the
     * master password and key blobs — between them, everything an offline attack on the vault
     * needs. None of it may leave the application.
     */
    public function testViewActionDoesNotReturnCredentialMaterial(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_VIEW, ['id' => self::ADMIN_ID]);

        $this->assertSame(200, $r->status);
        $this->assertUserCarriesNoCredentials($r->body->data);
    }

    /**
     * And the same for a listing, which returns every user in the installation at once — the same
     * material, multiplied by the size of the instance.
     */
    public function testSearchActionDoesNotReturnCredentialMaterial(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_SEARCH, []);

        $this->assertSame(200, $r->status);
        $this->assertGreaterThan(1, count($r->body->data));

        foreach ($r->body->data as $user) {
            $this->assertUserCarriesNoCredentials($user);
        }
    }

    /**
     * Deleting answers with the user that was removed, which is the same row read out of the
     * database — so it is the same exposure by another route.
     */
    public function testDeleteActionDoesNotReturnCredentialMaterial(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => self::DELETABLE_ID]);

        $this->assertSame(200, $r->status);
        $this->assertUserCarriesNoCredentials($r->body->data);
    }

    private function assertUserCarriesNoCredentials(stdClass $user): void
    {
        foreach (['pass', 'hashSalt', 'mPass', 'mKey'] as $secret) {
            $this->assertObjectNotHasProperty($secret, $user, sprintf('"%s" must not leave the API', $secret));
        }

        // Guarding against the opposite mistake: an answer stripped down to nothing would pass the
        // assertions above while making the endpoint useless.
        $this->assertNotEmpty($user->login);
        $this->assertNotEmpty($user->name);
    }

    public function testViewAction(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_VIEW, ['id' => self::ADMIN_ID]);

        $this->assertSame(200, $r->status);
        $this->assertSame(self::ADMIN_ID, $r->body->data->id);
        $this->assertSame('admin', $r->body->data->login);
        $this->assertTrue($r->body->data->isAdminApp);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_VIEW, ['id' => 1000]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('User does not exist', $r->body->error->message);
    }

    public function testSearchAction(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_SEARCH, ['text' => 'admin']);

        $this->assertSame(200, $r->status);
        $this->assertCount(1, $r->body->data);
        $this->assertSame('admin', $r->body->data[0]->login);

        // The listing joins the names in, which is what a caller wants a list for.
        $this->assertNotEmpty($r->body->data[0]->userGroupName);
        $this->assertNotEmpty($r->body->data[0]->userProfileName);
    }

    public function testSearchActionNoMatches(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_SEARCH, ['text' => 'nobody by that name']);

        $this->assertSame(200, $r->status);
        $this->assertCount(0, $r->body->data);
    }

    public function testDeleteAction(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => self::DELETABLE_ID]);

        $this->assertSame(200, $r->status);
        $this->assertSame('User removed', $r->body->message);
        $this->assertFalse($this->userExists(self::DELETABLE_ID));
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => 1000]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('User does not exist', $r->body->error->message);
    }

    /**
     * A token cannot be used to delete the identity it is acting as. Nothing else would stop it,
     * and the last administrator deleting themselves locks the installation out of its own
     * administration.
     */
    public function testDeleteActionRefusesTheCallersOwnAccount(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => self::ADMIN_ID]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Unable to delete, user in use', $r->body->error->message);
        $this->assertTrue($this->userExists(self::ADMIN_ID), 'the caller is still there');
    }

    private function userExists(int $id): bool
    {
        $statement = getDbHandler()->getConnection()->prepare('SELECT COUNT(*) FROM `User` WHERE id = ?');
        $statement->execute([$id]);

        return (int)$statement->fetchColumn() === 1;
    }

}
