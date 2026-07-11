<?php

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Infrastructure\Database\QueryData;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the notification delete endpoint for single and batch deletions.
 * The delete service methods (deleteAdmin / delete / deleteByIdBatch) go directly
 * to the repository without calling getById, so no ownership resolver is needed.
 */
#[Group('integration')]
class DeleteControllerTest extends IntegrationTestCase
{
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
}
