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
use SP\Domain\Crypt\Hash;
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

    private const PARAMS = [
        'name' => 'API User',
        'login' => 'api_user',
        'pass' => 'a-provisioned-password',
        'email' => 'api_user@syspass.org',
        'notes' => "API test\nnotes",
        'userGroupId' => 1,
        'userProfileId' => 1,
    ];

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

    public function testCreateAction(): void
    {
        $r = $this->createUser();

        $this->assertSame(201, $r->status);
        $this->assertSame('User added', $r->body->message);
        $this->assertSame($r->body->itemId, $r->body->data->id);

        // Read back rather than trusting the echo: what was stored is what matters.
        $view = $this->callApi(AclActionsInterface::USER_VIEW, ['id' => $r->body->itemId]);

        $this->assertSame(self::PARAMS['name'], $view->body->data->name);
        $this->assertSame(self::PARAMS['login'], $view->body->data->login);
        $this->assertSame(self::PARAMS['email'], $view->body->data->email);
        $this->assertSame(self::PARAMS['notes'], $view->body->data->notes);
        $this->assertSame(self::PARAMS['userGroupId'], $view->body->data->userGroupId);
        $this->assertSame(self::PARAMS['userProfileId'], $view->body->data->userProfileId);
    }

    /**
     * The password is stored hashed, and it is the one that was sent — so a user provisioned
     * through the API can actually sign in afterwards, which is the whole point of provisioning
     * one.
     */
    public function testCreateActionStoresThePasswordHashed(): void
    {
        $id = $this->createUser()->body->itemId;

        $stored = $this->getStoredPassword($id);

        $this->assertNotSame(self::PARAMS['pass'], $stored, 'the password is not stored as sent');
        $this->assertTrue(Hash::checkHashKey(self::PARAMS['pass'], $stored ?? ''));
    }

    /**
     * And it does not come back in the answer. The create response echoes the user that was built
     * from the request, which is the one object in the API that holds a password in the clear.
     */
    public function testCreateActionDoesNotEchoThePasswordBack(): void
    {
        $r = $this->createUser();

        $this->assertObjectNotHasProperty('pass', $r->body->data);
        $this->assertStringNotContainsString(self::PARAMS['pass'], json_encode($r->body));
    }

    /**
     * The group and profile a user belongs to decide what they can reach, so neither may be
     * guessed by the endpoint — a user created without them would land in whatever group id 0
     * happens to be.
     */
    public function testCreateActionRequiredParameters(): void
    {
        foreach (['name', 'login', 'pass', 'userGroupId', 'userProfileId'] as $required) {
            $params = self::PARAMS;
            unset($params[$required]);

            $r = $this->callApi(AclActionsInterface::USER_CREATE, $params);

            $this->assertSame(400, $r->status, sprintf('"%s" must be required', $required));
            $this->assertSame('Wrong parameters', $r->body->error->message);
        }
    }

    /**
     * Logins identify people; a duplicate one is refused with the reason rather than a generic
     * failure.
     */
    public function testCreateActionDuplicatedLogin(): void
    {
        $params = self::PARAMS;
        $params['login'] = 'admin';

        $r = $this->callApi(AclActionsInterface::USER_CREATE, $params);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated user login/email', $r->body->error->message);
    }

    /**
     * A group that does not exist is refused by the foreign key rather than leaving a user
     * pointing at nothing.
     */
    public function testCreateActionNonExistantGroup(): void
    {
        $params = self::PARAMS;
        $params['userGroupId'] = 1000;

        $r = $this->callApi(AclActionsInterface::USER_CREATE, $params);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Referenced record not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createUser()->body->itemId;

        $params = [
            'id' => $id,
            'name' => 'API User edited',
            'login' => 'api_user_edited',
            'email' => 'edited@syspass.org',
            'userGroupId' => 2,
            'userProfileId' => 2,
        ];

        $r = $this->callApi(AclActionsInterface::USER_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('User updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::USER_VIEW, ['id' => $id]);

        $this->assertSame($params['name'], $view->body->data->name);
        $this->assertSame($params['login'], $view->body->data->login);
        $this->assertSame($params['userGroupId'], $view->body->data->userGroupId);
    }

    public function testEditActionRequiredParameters(): void
    {
        $id = $this->createUser()->body->itemId;

        $r = $this->callApi(AclActionsInterface::USER_EDIT, ['id' => $id]);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);

        // And the answer says which parameters the endpoint takes, as the group, category, client
        // and tag ones have always done. It used to come back empty, so a caller was told a
        // parameter was wrong and left to guess which.
        $this->assertStringContainsString('help', $r->body->error->detail);
        $this->assertStringContainsString('login', $r->body->error->detail);
        $this->assertStringContainsString('userGroupId', $r->body->error->detail);
    }

    /**
     * Editing leaves the credential material alone: it is not among the columns the update writes,
     * so a user edited through the API can still sign in afterwards.
     */
    public function testEditActionKeepsTheStoredPassword(): void
    {
        $before = $this->getStoredPassword(2);

        $r = $this->callApi(
            AclActionsInterface::USER_EDIT,
            [
                'id' => 2,
                'name' => 'Demo edited',
                'login' => 'demo',
                'userGroupId' => 2,
                'userProfileId' => 2,
            ]
        );

        $this->assertSame(200, $r->status);
        $this->assertSame($before, $this->getStoredPassword(2));
        $this->assertNotEmpty($before);
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

    private function createUser(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::USER_CREATE, $params ?? self::PARAMS);
    }

    /**
     * Read straight from the table: the endpoints deliberately do not answer with the stored
     * password, so the database is the only place left to check it from.
     */
    private function getStoredPassword(int $id): ?string
    {
        $statement = getDbHandler()->getConnection()->prepare('SELECT `pass` FROM `User` WHERE id = ?');
        $statement->execute([$id]);

        $pass = $statement->fetchColumn();

        return $pass === false ? null : (string)$pass;
    }

    private function userExists(int $id): bool
    {
        $statement = getDbHandler()->getConnection()->prepare('SELECT COUNT(*) FROM `User` WHERE id = ?');
        $statement->execute([$id]);

        return (int)$statement->fetchColumn() === 1;
    }

}
