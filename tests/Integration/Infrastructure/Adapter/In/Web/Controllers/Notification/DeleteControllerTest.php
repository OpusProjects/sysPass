<?php

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Notification\Models\Notification;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the notification delete endpoint for single and batch deletions.
 *
 * The owner-scoped delete methods (delete / deleteByIdBatch) read the notification first, so that
 * a user cannot remove somebody else's by its id, and the non-admin tests below therefore have to
 * answer that read with a notification the signed-in user owns. The admin ones (deleteAdmin /
 * deleteAdminBatch) delete any notification by design and need no such row.
 *
 * The ACL denial itself (the very first check in DeleteController::deleteAction()) is not
 * reachable here: this harness's AclInterface double is permanently open. It is covered instead
 * as a unit test, with the ACL mocked closed
 * (tests/Unit/.../Web/Controllers/Notification/DeleteControllerTest.php).
 */
#[Group('integration')]
class DeleteControllerTest extends IntegrationTestCase
{
    private bool $isAdminApp = false;
    private ?UserDto $userDto = null;

    /**
     * Memoised: the generator mints a fresh random user on every call, and these tests have to
     * know who is signed in — the notification below is owned by them.
     */
    protected function getUserDataDto(): UserDto
    {
        return $this->userDto ??= parent::getUserDataDto()->mutate(['isAdminApp' => $this->isAdminApp]);
    }

    /**
     * Answers the ownership read with a notification belonging to the signed-in user, and
     * everything else with the affected-row count the caller expects.
     */
    private function givenTheNotificationsAreMine(int $affectedRows): void
    {
        $mine = new Notification(['userId' => $this->getUserDataDto()->id]);

        $this->databaseQueryResolver = function (QueryData $queryData) use ($mine, $affectedRows): QueryResult {
            if ($queryData->getMapClassName() === Notification::class) {
                return new QueryResult([$mine]);
            }

            return new QueryResult([], $affectedRows, 0);
        };
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteSingle(): void
    {
        $this->givenTheNotificationsAreMine(1);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Notification deleted","data":null}');
    }

    /**
     * An administrator deletes through the "any notification" path (deleteAdmin), not the
     * owner-scoped one — a different repository call than testDeleteSingle exercises.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteSingleAsAdmin(): void
    {
        $this->isAdminApp = true;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Notification deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteMultiple(): void
    {
        // The batch-delete service verifies affectedNumRows == count(ids), and reads each one
        // first to check it belongs to the caller.
        $this->givenTheNotificationsAreMine(2);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Notifications deleted","data":null}');
    }

    /**
     * The admin batch path (deleteAdminBatch) is a different repository call from the plain-user
     * one testDeleteMultiple exercises.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteMultipleAsAdmin(): void
    {
        $this->isAdminApp = true;

        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 2, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Notifications deleted","data":null}');
    }

    /**
     * Neither a single id nor an "items" batch: there is nothing to delete, so the request is
     * refused before any service call is made.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteWithNoItemsSelected(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/delete'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"No items selected","data":null}');
    }

    /**
     * An id that does not affect any row (not the caller's, or already gone) is reported as "not
     * found" rather than as a raw error page — this is also the shape a non-owned notification's id
     * comes back as, since the repository scopes the delete itself.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteSingleNotFound(): void
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 0, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/delete/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Notification not found","data":null}');
    }

    /**
     * A batch that removes fewer rows than ids requested is reported as an error rather than as a
     * partial success — the caller asked for both and got only one back.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteMultiplePartialFailure(): void
    {
        // Both belong to the caller, but only one row is affected: the count mismatch is what
        // this asserts, and it has to be reached rather than short-circuited by the ownership read.
        $this->givenTheNotificationsAreMine(1);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the notifications","data":null}');
    }
}
