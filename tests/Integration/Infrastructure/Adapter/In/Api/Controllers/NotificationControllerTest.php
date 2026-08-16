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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Integration\Infrastructure\Adapter\In\Api\ApiTestCase;
use stdClass;

/**
 * REST API tests for the Notification controllers.
 *
 * A notification carries a message addressed to a specific user (or to nobody in
 * particular, for a broadcast one), so the question worth pinning down is who may
 * read and modify whose: whether a caller acting as one user can view, edit, check
 * or delete a notification addressed to somebody else. The fixture seeds
 * notification #1 addressed to user 2 ("demo"), which every ownership test below
 * uses as the notification a non-owner must not be able to reach.
 */
#[Group('integration')]
class NotificationControllerTest extends ApiTestCase
{
    /** A fixture user that is not an application administrator. */
    private const NON_ADMIN_USER_ID = 2;

    private const PARAMS = [
        'type' => 'API test',
        'component' => 'Accounts',
        'description' => "API test\ndescription",
        'userId' => 2,
        'sticky' => 0,
        'onlyAdmin' => 0,
    ];

    public function testCreateAction(): void
    {
        $r = $this->createNotification();

        $this->assertSame(201, $r->status);
        $this->assertSame('Notification added', $r->body->message);
        $this->assertGreaterThan(0, $r->body->itemId);

        // The create response is built straight from the submitted params, not read
        // back from the database — it has no `date`, for instance, which only the
        // DB assigns (`UNIX_TIMESTAMP()`). What actually persisted is what matters,
        // so fetch it back rather than trust the echo.
        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $r->body->itemId])->body->data;
        $this->assertSame(self::PARAMS['type'], $item->type);
        $this->assertSame(self::PARAMS['component'], $item->component);
        $this->assertSame(self::PARAMS['description'], $item->description);
        $this->assertSame(self::PARAMS['userId'], $item->userId);
        $this->assertFalse($item->sticky);
        $this->assertFalse($item->onlyAdmin);
        $this->assertFalse($item->checked);
        $this->assertGreaterThan(0, $item->date);
    }

    /**
     * A notification anybody can pin in front of everybody is not a notification.
     *
     * `sticky` shows it to every user in the installation and takes it out of reach of the
     * ordinary delete, which is `WHERE id = :id AND sticky = 0`; `onlyAdmin` puts it in the
     * administrators' queue. The web form has always read both only for an application
     * administrator — NotificationFormTest pins exactly that — and the API read them from
     * anybody, so an ordinary user holding a notification token could leave an undeletable
     * message in front of the whole installation.
     *
     * @throws \Exception
     */
    public function testANonAdminCannotPinANotificationForEverybody(): void
    {
        $params = self::PARAMS;
        $params['sticky'] = 1;
        $params['onlyAdmin'] = 1;

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_CREATE, $params, self::NON_ADMIN_USER_ID);

        $this->assertSame(201, $r->status);

        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $r->body->itemId])->body->data;

        $this->assertFalse($item->sticky, 'a non-admin pinned a notification for every user');
        $this->assertFalse($item->onlyAdmin, 'a non-admin put a notification in the admin queue');
    }

    /**
     * And an administrator still can, which is what the flags are for.
     *
     * @throws \Exception
     */
    public function testAnAdministratorStillPinsOne(): void
    {
        $params = self::PARAMS;
        $params['sticky'] = 1;
        $params['onlyAdmin'] = 1;

        $r = $this->createNotification($params);

        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $r->body->itemId])->body->data;

        $this->assertTrue($item->sticky);
        $this->assertTrue($item->onlyAdmin);
    }

    private function createNotification(?array $params = null): stdClass
    {
        return $this->callApi(AclActionsInterface::NOTIFICATION_CREATE, $params ?? self::PARAMS);
    }

    #[DataProvider('requiredParameterProvider')]
    public function testCreateActionRequiredParameters(string $missingParam): void
    {
        $params = self::PARAMS;
        unset($params[$missingParam]);

        $r = $this->createNotification($params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString($missingParam, $r->body->error->detail);
    }

    public static function requiredParameterProvider(): array
    {
        return [
            'type' => ['type'],
            'component' => ['component'],
            'description' => ['description'],
        ];
    }

    public function testViewAction(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1]);

        // Notification has no adapter/transformer, so the item is returned directly
        $this->assertSame(200, $r->status);
        $item = $r->body->data;
        $this->assertSame('Prueba', $item->type);
        $this->assertSame('Accounts', $item->component);
        $this->assertSame(2, $item->userId);
        $this->assertFalse($item->checked);
        $this->assertFalse($item->sticky);
    }

    public function testViewActionNonExistent(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 9999]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);
    }

    /**
     * User 2 ("demo") is not an app admin. Reaching their own notification only
     * works if the service scopes the lookup to the caller rather than trusting
     * whatever id is asked for.
     */
    public function testViewActionOwnerCanViewOwnNotification(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1], 2);

        $this->assertSame(200, $r->status);
        $this->assertSame(2, $r->body->data->userId);
    }

    /**
     * User 3 ("User A") is neither the addressee of notification #1 nor an app
     * admin. The service reports this exactly like a nonexistent id — not a
     * distinct "forbidden" — so a caller cannot use the response to tell an id
     * that exists-but-isn't-theirs apart from one that does not exist at all.
     */
    public function testViewActionDeniesNonOwner(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1], 3);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);
    }

    public function testEditAction(): void
    {
        $id = $this->createNotification()->body->itemId;

        $params = [
            'id' => $id,
            'type' => 'API test edit',
            'component' => 'Clients',
            'description' => 'edited description',
            'userId' => 3,
            'sticky' => 1,
            'onlyAdmin' => 1,
        ];

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_EDIT, $params);

        $this->assertSame(200, $r->status);
        $this->assertSame('Notification updated', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $id])->body->data;
        $this->assertSame($params['type'], $item->type);
        $this->assertSame($params['component'], $item->component);
        $this->assertSame($params['description'], $item->description);
        $this->assertSame($params['userId'], $item->userId);
        $this->assertTrue($item->sticky);
        $this->assertTrue($item->onlyAdmin);
    }

    #[DataProvider('requiredParameterProvider')]
    public function testEditActionRequiredParameters(string $missingParam): void
    {
        $id = $this->createNotification()->body->itemId;

        $params = ['id' => $id] + self::PARAMS;
        unset($params[$missingParam]);

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_EDIT, $params);

        $this->assertSame(400, $r->status);
        $this->assertSame('Wrong parameters', $r->body->error->message);
        $this->assertStringContainsString($missingParam, $r->body->error->detail);
    }

    /**
     * Editing reads the notification back before writing it, so an id that is not there is
     * reported as not found rather than answered with a success that changed nothing.
     */
    public function testEditActionNonExistent(): void
    {
        $params = self::PARAMS;
        $params['id'] = 9999;

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_EDIT, $params);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);
    }

    /**
     * The one that matters here. An update is by id alone, so without an ownership check anybody
     * who may edit their own notifications may rewrite everybody's — including reassigning one to
     * themselves. Refused as "not found", the same answer a truly missing id gets, so an id cannot
     * be probed for existence either.
     */
    public function testEditActionDeniesNonOwner(): void
    {
        $id = $this->createNotification()->body->itemId;

        $params = [
            'id' => $id,
            'type' => 'taken over',
            'component' => 'Clients',
            'description' => 'rewritten by somebody else',
            'userId' => 3,
        ];

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_EDIT, $params, 3);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);

        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $id])->body->data;
        $this->assertNotSame('taken over', $item->type, 'the refusal must not have written anyway');
        $this->assertNotSame(3, $item->userId, 'nor reassigned it');
    }

    /**
     * Addressed to user 2 ("demo"); demo (not an app admin) deletes their own
     * notification. This only succeeds if delete scopes access the same way
     * view and check do.
     */
    public function testDeleteAction(): void
    {
        $id = $this->createNotification()->body->itemId;

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_DELETE, ['id' => $id], 2);

        $this->assertSame(200, $r->status);
        $this->assertSame('Notification removed', $r->body->message);
        $this->assertSame($id, $r->body->itemId);

        $view = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $id]);
        $this->assertInstanceOf(stdClass::class, $view->body->error, 'a deleted notification is gone');
    }

    public function testDeleteActionNonExistent(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_DELETE, ['id' => 9999]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);
    }

    /**
     * User 3 is neither the addressee (user 2) nor an app admin: the same lookup
     * that guards view()/check() guards delete(), so the notification survives.
     */
    public function testDeleteActionDeniesNonOwner(): void
    {
        $id = $this->createNotification()->body->itemId;

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_DELETE, ['id' => $id], 3);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);

        $view = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => $id]);
        $this->assertSame(200, $view->status, 'the denial must not have deleted it anyway');
    }

    /**
     * Notification #2 (fixture) is sticky. The repository's delete query filters
     * "AND sticky = 0" to keep sticky notifications from being removed, so the
     * delete affects no rows and is reported exactly like a nonexistent id — even
     * though this same call just read the row via getById() a moment earlier.
     */
    public function testDeleteActionRefusesStickyNotification(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_DELETE, ['id' => 2]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);

        $view = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 2]);
        $this->assertSame(200, $view->status, 'the sticky notification was never actually deleted');
    }

    /**
     * Notification #1 (fixture) is addressed to user 2 and starts unchecked.
     * Checking it must flip `checked` and leave everything else about the row
     * alone — check is not a partial edit of arbitrary fields.
     */
    public function testCheckAction(): void
    {
        $before = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1], 2)->body->data;
        $this->assertFalse($before->checked);

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_CHECK, ['id' => 1], 2);

        $this->assertSame(200, $r->status);
        $this->assertSame('Notification marked as read', $r->body->message);
        $this->assertSame(1, $r->body->itemId);
        $this->assertNull($r->body->data);

        $after = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1], 2)->body->data;
        $this->assertTrue($after->checked);
        $this->assertSame($before->type, $after->type);
        $this->assertSame($before->component, $after->component);
        $this->assertSame($before->description, $after->description);
        $this->assertSame($before->userId, $after->userId);
    }

    public function testCheckActionNonExistent(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_CHECK, ['id' => 9999]);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);
    }

    /**
     * User 3 is neither the addressee (user 2) nor an app admin: the same lookup
     * that guards view()/delete() guards check(), and the denial happens before
     * any write — `checked` is untouched.
     */
    public function testCheckActionDeniesNonOwner(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_CHECK, ['id' => 1], 3);

        $this->assertInstanceOf(stdClass::class, $r->body->error);
        $this->assertSame('Notification not found', $r->body->error->message);

        $item = $this->callApi(AclActionsInterface::NOTIFICATION_VIEW, ['id' => 1], 2)->body->data;
        $this->assertFalse($item->checked);
    }

    public function testSearchActionMatchesFilter(): void
    {
        $this->createNotification([
            'type' => 'ApiSearchMarker',
            'component' => 'Accounts',
            'description' => 'x',
            'userId' => 1,
        ]);

        $r = $this->callApi(AclActionsInterface::NOTIFICATION_SEARCH, ['text' => 'ApiSearchMarker']);

        $this->assertSame(200, $r->status);
        $this->assertSame(1, $r->body->count);
        $this->assertSame('ApiSearchMarker', $r->body->data[0]->type);
    }

    public function testSearchActionNoMatch(): void
    {
        $r = $this->callApi(AclActionsInterface::NOTIFICATION_SEARCH, ['text' => 'NoSuchTextAtAll']);

        $this->assertSame(200, $r->status);
        $this->assertSame(0, $r->body->count);
        $this->assertCount(0, $r->body->data);
    }
}
