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
 * Covers the profile endpoints over the REST API. None of them had tests.
 *
 * A profile is nothing but a set of permission flags, and both create and edit take that set as a
 * single opaque "profile" string — a JSON object of flag names to booleans — rather than
 * individual parameters per permission. Whatever is in that string becomes the row's entire
 * permission set: the controllers store it verbatim (no merge with what a profile already had),
 * so what these tests care about most is the round trip — the flags posted are the flags that come
 * back — and what happens to a flag simply left out of a request.
 */
#[Group('integration')]
class ProfileControllerTest extends ApiTestCase
{
    /** A fixture profile name, used to trigger the duplicate-name check. */
    private const EXISTING_NAME = 'Admin';

    /**
     * The permissions posted are the permissions stored, verbatim — not expanded to the full set
     * of flags with the rest defaulted, and not reinterpreted in any way. A caller that asks for
     * three flags gets exactly three flags back, nothing more.
     */
    public function testCreateActionStoresPermissionFlags(): void
    {
        $profile = json_encode(['accView' => true, 'accAdd' => true, 'mgmUsers' => true]);

        $r = $this->createProfile(['name' => 'API profile', 'profile' => $profile]);

        $this->assertSame(201, $r->status);
        $this->assertSame('Profile added', $r->body->message);
        $this->assertGreaterThan(0, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::PROFILE_VIEW, ['id' => $r->body->itemId]);
        $flags = json_decode($view->body->data->profile, true);

        $this->assertSame(['accView' => true, 'accAdd' => true, 'mgmUsers' => true], $flags);
    }

    /**
     * Both the name and the permissions are enforced by the controller.
     */
    public function testCreateActionRequiredParameters(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_CREATE, []);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
    }

    /**
     * The permissions are the profile, and the column behind them is NOT NULL. Omitting them is
     * refused as the missing parameter it is — it used to reach the database and come back as a
     * raw integrity error, which told the caller nothing about which parameter to supply.
     */
    public function testCreateActionWithoutPermissionsIsRefused(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_CREATE, ['name' => 'API profile no perms']);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
    }

    /**
     * And the same on an edit, where dropping them would have blanked the permissions of a profile
     * that people are already assigned to.
     */
    public function testEditActionWithoutPermissionsIsRefused(): void
    {
        $id = $this->createProfile(
            ['name' => 'API profile to edit', 'profile' => json_encode(['accView' => true])]
        )->body->itemId;

        $r = $this->callApi(AclActionsInterface::PROFILE_EDIT, ['id' => $id, 'name' => 'API profile edited']);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
    }

    public function testCreateActionDuplicateName(): void
    {
        $r = $this->createProfile(['name' => self::EXISTING_NAME, 'profile' => json_encode(['accView' => true])]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Duplicated profile name', $r->body->error->message);
    }

    public function testViewActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_VIEW, ['id' => 10000]);

        $this->assertSame(404, $r->status);
        $this->assertSame('Profile not found', $r->body->error->message);
    }

    /**
     * Edit takes the same single opaque "profile" string create does, and stores it the same
     * way: wholesale, not merged with what the profile already had. A flag that was true before
     * and is simply not mentioned in the edit's payload does not survive it — it is gone, not
     * left alone.
     */
    public function testEditActionReplacesPermissionsWholesale(): void
    {
        $create = $this->createProfile([
            'name' => 'API profile to edit',
            'profile' => json_encode(['accView' => true, 'accAdd' => true, 'accDelete' => true]),
        ]);
        $id = $create->body->itemId;

        $edit = $this->callApi(AclActionsInterface::PROFILE_EDIT, [
            'id' => $id,
            'name' => 'API profile to edit',
            'profile' => json_encode(['accView' => true]),
        ]);

        $this->assertSame(200, $edit->status);
        $this->assertSame('Profile updated', $edit->body->message);

        $view = $this->callApi(AclActionsInterface::PROFILE_VIEW, ['id' => $id]);
        $flags = json_decode($view->body->data->profile, true);

        $this->assertSame(['accView' => true], $flags, 'accAdd and accDelete were dropped, not kept');
    }

    public function testEditActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_EDIT, [
            'id' => 10000,
            'name' => 'does not exist',
            'profile' => json_encode(['accView' => true]),
        ]);

        $this->assertSame(500, $r->status);
        $this->assertSame('Error while updating the profile', $r->body->error->message);
    }

    public function testDeleteAction(): void
    {
        $create = $this->createProfile(['name' => 'API profile to delete', 'profile' => json_encode(['accView' => true])]);
        $id = $create->body->itemId;

        $r = $this->callApi(AclActionsInterface::PROFILE_DELETE, ['id' => $id]);

        $this->assertSame(200, $r->status);
        $this->assertSame('Profile removed', $r->body->message);

        $view = $this->callApi(AclActionsInterface::PROFILE_VIEW, ['id' => $id]);
        $this->assertInstanceOf(stdClass::class, $view->body->error, 'a removed profile is gone');
    }

    public function testDeleteActionNonExistant(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_DELETE, ['id' => 10000]);

        $this->assertSame(404, $r->status);
        $this->assertSame('Profile not found', $r->body->error->message);
    }

    public function testSearchAction(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_SEARCH, ['text' => self::EXISTING_NAME]);

        $this->assertSame(200, $r->status);
        $this->assertGreaterThan(0, $r->body->count);
        $this->assertSame(self::EXISTING_NAME, $r->body->data[0]->name);
    }

    public function testSearchActionNoMatches(): void
    {
        $r = $this->callApi(AclActionsInterface::PROFILE_SEARCH, ['text' => 'no profile has this name']);

        $this->assertSame(200, $r->status);
        $this->assertSame(0, $r->body->count);
    }

    private function createProfile(array $params): stdClass
    {
        return $this->callApi(AclActionsInterface::PROFILE_CREATE, $params);
    }
}
