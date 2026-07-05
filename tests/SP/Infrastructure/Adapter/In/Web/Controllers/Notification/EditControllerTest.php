<?php

declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Generators\UserDataGenerator;
use SP\Tests\IntegrationTestCase;

/**
 * Covers the notification edit endpoint (renders the editable notification form).
 * Uses an admin context so the service's ownership check is bypassed and the
 * user-select box is rendered (verifying that empty user list doesn't error).
 */
#[Group('integration')]
class EditControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testEditAction(): void
    {
        $this->addDatabaseMapperResolver(
            NotificationModel::class,
            new QueryResult([new NotificationModel()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'notification/edit/100'])
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
