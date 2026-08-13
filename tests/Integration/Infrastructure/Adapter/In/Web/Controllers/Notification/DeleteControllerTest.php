<?php

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the notification delete endpoint for single and batch deletions.
 * The delete service methods (deleteAdmin / delete / deleteByIdBatch) go directly
 * to the repository without calling getById, so no ownership resolver is needed.
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

    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['isAdminApp' => $this->isAdminApp]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testDeleteSingle(): void
    {
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
        // The batch-delete service verifies affectedNumRows == count(ids).
        // Return a QueryResult whose affectedNumRows matches the 2 ids sent.
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
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 1, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the notifications","data":null}');
    }
}
