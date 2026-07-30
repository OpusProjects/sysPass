<?php

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Notification;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\User\Dtos\UserDto;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the notification saveCreate endpoint, whose form composes the description
 * through NotificationMessage before validating it.
 */
#[Group('integration')]
class SaveCreateControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreate(): void
    {
        $data = [
            'notification_type' => self::$faker->word(),
            'notification_component' => self::$faker->word(),
            'notification_description' => self::$faker->sentence(),
            'notification_user' => self::$faker->randomNumber(3),
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"OK","description":"Notification created"/');
    }

    /**
     * An absent description is null, which NotificationMessage::addDescription() —
     * typed string — cannot take. It must be reported as a validation error rather
     * than reaching that call as a TypeError / HTTP 500.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutDescriptionIsRejectedNotFatal(): void
    {
        $data = [
            'notification_type' => self::$faker->word(),
            'notification_component' => self::$faker->word(),
            'notification_user' => self::$faker->randomNumber(3),
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        // Clean validation error, not a TypeError. (data carries a debug trace when
        // DEBUG is on, so match status + description rather than the whole body.)
        $this->expectOutputRegex('/\{"status":"ERROR","description":"A description is needed"/');
    }

    /**
     * A present-but-empty description composes to NotificationMessage's wrapper <div>,
     * which is non-empty — so it has to be rejected on the raw value, not the HTML.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithEmptyDescriptionIsRejected(): void
    {
        $data = [
            'notification_type' => self::$faker->word(),
            'notification_component' => self::$faker->word(),
            'notification_description' => '',
            'notification_user' => self::$faker->randomNumber(3),
        ];

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'notification/saveCreate'], $data)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/\{"status":"ERROR","description":"A description is needed"/');
    }

    protected function getUserDataDto(): UserDto
    {
        return UserDto::fromModel(UserDataGenerator::factory()->buildUserData())
            ->mutate(['isAdminApp' => true, 'isAdminAcc' => false, 'userGroupName' => self::$faker->colorName()]);
    }
}
