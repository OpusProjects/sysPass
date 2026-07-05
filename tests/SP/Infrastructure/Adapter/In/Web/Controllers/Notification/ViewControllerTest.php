<?php

declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Generators\UserDataGenerator;
use SP\Tests\IntegrationTestCase;

/**
 * Covers the notification view endpoint (renders the read-only notification form).
 * Uses an admin context so the service's ownership check is bypassed.
 */
#[Group('integration')]
class ViewControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testViewIsDeniedWithoutAcl(): void
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/view/100']),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testViewAction(): void
    {
        $this->addDatabaseMapperResolver(
            NotificationModel::class,
            new QueryResult([new NotificationModel()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/view/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"OK".*"html".*box-popup/s');
    }

    protected function getUserDataDto(): UserDto
    {
        return UserDto::fromModel(UserDataGenerator::factory()->buildUserData())
            ->mutate(['isAdminApp' => true, 'isAdminAcc' => false, 'userGroupName' => self::$faker->colorName()]);
    }
}
