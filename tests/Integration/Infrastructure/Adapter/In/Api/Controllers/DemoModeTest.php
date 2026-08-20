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
 */

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Api\Controllers;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\User;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

use function SP\Tests\getDbHandler;

/**
 * What demo mode refuses, asked through the API instead of the interface.
 *
 * The web enforces demo mode in five config actions and in `UserForm`; the API did not mention it
 * anywhere. That gap matters more than most, because a demo deployment is the one place where the
 * caller is *meant* to hold administrator credentials — they are published so people can try it.
 * The ACL therefore stops nobody: sign in as the demo admin, mint a token, and the API ran the
 * backup, the export or the user change the interface had just refused. Changing the demo admin's
 * password that way ends the demo for everyone who comes after.
 *
 * Each refusal is paired with the same call succeeding on a user demo mode does not protect, so
 * none of these can be satisfied by an endpoint that simply stopped working.
 */
#[Group('integration')]
class DemoModeTest extends ApiTestCase
{
    /** The account the demo publishes — `id 2`, login `demo`, in the fixture as in a real demo. */
    private const DEMO_ID = User::DEMO_ADMIN_ID;
    /** A fixture user demo mode says nothing about, used as the control on every refusal. */
    private const ORDINARY_ID = 5;

    private const REFUSAL = 'Ey, this is a DEMO!!';

    protected function isDemoEnabled(): bool
    {
        return true;
    }

    /**
     * A backup is the whole database — every account's encrypted secret and the master password
     * hash — written to a file. The web refuses to make one on a demo; the API made it.
     */
    #[Test]
    public function backupIsRefusedOnADemoInstance(): void
    {
        $r = $this->callApi(AclActionsInterface::CONFIG_BACKUP_RUN, []);

        $this->assertRefused($r);
        $this->assertEmpty(glob(self::backupPath() . '/sysPass_db-*'), 'no dump may be written');
        $this->assertEmpty(glob(self::backupPath() . '/sysPass_app-*'), 'no archive may be written');
    }

    /**
     * The XML export is the same data in the other format, and was equally reachable.
     */
    #[Test]
    public function exportIsRefusedOnADemoInstance(): void
    {
        $r = $this->callApi(AclActionsInterface::CONFIG_EXPORT_RUN, ['password' => 'an-export-password']);

        $this->assertRefused($r);
        $this->assertEmpty(glob(self::backupPath() . '/*.xml'), 'no export may be written');
    }

    /**
     * The demo admin's row must come through the call unchanged — asserting only the error message
     * would pass just as well if the update had been applied and the response happened to fail.
     */
    #[Test]
    public function theDemoAdminCannotBeEdited(): void
    {
        $before = $this->loginOf(self::DEMO_ID);

        $r = $this->callApi(
            AclActionsInterface::USER_EDIT,
            [
                'id' => self::DEMO_ID,
                'name' => 'renamed by a visitor',
                'login' => 'not_demo',
                'userGroupId' => 1,
                'userProfileId' => 1,
            ]
        );

        $this->assertRefused($r);
        $this->assertSame($before, $this->loginOf(self::DEMO_ID), 'the demo account is unchanged');
    }

    #[Test]
    public function theDemoAdminCannotBeDeleted(): void
    {
        $r = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => self::DEMO_ID]);

        $this->assertRefused($r);
        $this->assertNotNull($this->loginOf(self::DEMO_ID), 'the demo account is still there');
    }

    /**
     * The control for both user refusals: demo mode protects the published account, not the
     * endpoint. Trying the demo is most of the point of running one, so every other user stays
     * editable and removable.
     */
    #[Test]
    public function anotherUserIsStillEditableAndRemovableOnADemoInstance(): void
    {
        $edit = $this->callApi(
            AclActionsInterface::USER_EDIT,
            [
                'id' => self::ORDINARY_ID,
                'name' => 'renamed on a demo',
                'login' => 'renamed_on_a_demo',
                'userGroupId' => 1,
                'userProfileId' => 1,
            ]
        );

        $this->assertSame(200, $edit->status);
        $this->assertSame('User updated', $edit->body->message);
        $this->assertSame('renamed_on_a_demo', $this->loginOf(self::ORDINARY_ID));

        $delete = $this->callApi(AclActionsInterface::USER_DELETE, ['id' => self::ORDINARY_ID]);

        $this->assertSame(200, $delete->status);
        $this->assertNull($this->loginOf(self::ORDINARY_ID), 'the ordinary user is gone');
    }

    private function assertRefused(stdClass $response): void
    {
        $this->assertInstanceOf(stdClass::class, $response->body->error ?? null);
        $this->assertSame(self::REFUSAL, $response->body->error->message);
    }

    /**
     * Read straight from the table: whether the call went through is a question about the row, and
     * the endpoint that would answer it is one of the ones under test.
     */
    private function loginOf(int $id): ?string
    {
        $statement = getDbHandler()->getConnection()->prepare('SELECT login FROM `User` WHERE id = ?');
        $statement->execute([$id]);

        $login = $statement->fetchColumn();

        return $login === false ? null : (string)$login;
    }
}
