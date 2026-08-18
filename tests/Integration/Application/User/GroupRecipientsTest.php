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

namespace SP\Tests\Integration\Application\User;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Everybody in a group is one recipient, however many ways they are in it.
 *
 * `getUserEmailForGroup()` is what addresses the mail carrying a newly issued temporary master
 * password. A user belongs to the group either by their own `userGroupId` or through
 * `UserToUserGroup`, and the join carries one row per membership they hold — so
 * `User.userGroupId = :id`, true on every one of those rows, returned somebody in the group
 * directly once for each *other* group they were in. `sendByEmailForGroup()` mails what it is
 * handed, so that user received the master password twice, or five times, depending on how many
 * groups they happened to belong to.
 *
 * Against a real database, because the defect is in what the join returns and a mocked repository
 * returns whatever the test says.
 */
#[Group('integration')]
final class GroupRecipientsTest extends TestCase
{
    use DatabaseTrait;

    private const TARGET_GROUP = 2;

    private string $root;
    private string $configPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-group-recipients-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->backupPath()] as $dir) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail(sprintf('Directory "%s" was not created', $dir));
            }
        }

        file_put_contents(
            FileSystem::buildPath($this->configPath, 'config.xml'),
            getResource('config', 'config.xml')
        );

        $this->pdo = getDbHandler()->getConnection();
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * Somebody whose own group is the one being mailed, who is also in others, is addressed once.
     */
    #[Test]
    public function aMemberOfSeveralGroupsIsOneRecipient(): void
    {
        $login = 'recip_' . bin2hex(random_bytes(4));
        $userId = $this->givenAUserInTheTargetGroup($login);

        // ...and in two more, which is what used to multiply them.
        foreach ($this->twoOtherGroupIds() as $groupId) {
            $this->alsoAMemberOf($userId, $groupId);
        }

        $logins = $this->recipientLoginsFor(self::TARGET_GROUP);

        self::assertContains($login, $logins, 'setup: the user must be a recipient at all');
        self::assertSame(
            1,
            count(array_keys($logins, $login, true)),
            'a recipient is a person, not a membership — the master password is mailed to them once'
        );
    }

    /**
     * And the list is still everybody, not just the first of them.
     */
    #[Test]
    public function everyMemberOfTheGroupIsARecipient(): void
    {
        $first = 'recipa_' . bin2hex(random_bytes(4));
        $second = 'recipb_' . bin2hex(random_bytes(4));

        $this->givenAUserInTheTargetGroup($first);
        $this->alsoAMemberOf($this->givenAUserInGroup($second, $this->twoOtherGroupIds()[0]), self::TARGET_GROUP);

        $logins = $this->recipientLoginsFor(self::TARGET_GROUP);

        self::assertContains($first, $logins, 'a member by their own group');
        self::assertContains($second, $logins, 'a member through UserToUserGroup');
    }

    /**
     * @return string[]
     */
    private function recipientLoginsFor(int $groupId): array
    {
        return array_map(
            static fn(object $user) => $user->getLogin(),
            $this->buildContainer()->get(UserService::class)->getUserEmailForGroup($groupId)
        );
    }

    private function givenAUserInTheTargetGroup(string $login): int
    {
        return $this->givenAUserInGroup($login, self::TARGET_GROUP);
    }

    private function givenAUserInGroup(string $login, int $groupId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `User` (`name`, `login`, `email`, `userGroupId`, `userProfileId`, `isDisabled`, `pass`, `hashSalt`)
             VALUES (:name, :login, :email, :groupId, 1, 0, :pass, :hashSalt)'
        );

        $statement->execute(
            [
                'name' => 'Recipient Test User',
                'login' => $login,
                'email' => $login . '@example.invalid',
                'groupId' => $groupId,
                'pass' => '',
                'hashSalt' => '',
            ]
        );

        return (int)$this->pdo->lastInsertId();
    }

    private function alsoAMemberOf(int $userId, int $groupId): void
    {
        $this->pdo
            ->prepare('INSERT INTO `UserToUserGroup` (`userId`, `userGroupId`) VALUES (:userId, :groupId)')
            ->execute(['userId' => $userId, 'groupId' => $groupId]);
    }

    /**
     * Two groups from the fixtures that are not the one being mailed.
     *
     * @return int[]
     */
    private function twoOtherGroupIds(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `UserGroup` WHERE `id` <> :target ORDER BY `id` LIMIT 2'
        );
        $statement->execute(['target' => self::TARGET_GROUP]);

        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        self::assertCount(2, $ids, 'the fixtures must provide two other groups to belong to');

        return $ids;
    }

    private function buildContainer(): ContainerInterface
    {
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'cli');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        $coreDefinitions['paths'] = array_map(
            fn(array $path) => match ($path[0]) {
                Path::CACHE => [Path::CACHE, $this->cachePath()],
                Path::TMP => [Path::TMP, $this->tmpPath()],
                Path::BACKUP => [Path::BACKUP, $this->backupPath()],
                default => $path,
            },
            $coreDefinitions['paths']
        );

        $moduleDefinitions = FileSystem::require(
            FileSystem::buildPath(REAL_APP_ROOT, 'src', 'Infrastructure', 'Adapter', 'In', 'Cli', 'module.php')
        );

        $builder = new ContainerBuilder();
        $builder->addDefinitions(
            DomainDefinitions::getDefinitions(),
            $coreDefinitions,
            $moduleDefinitions,
            [DbStorageHandler::class => getDbHandler()]
        );

        return $builder->build();
    }

    private function cachePath(): string
    {
        return FileSystem::buildPath($this->root, 'cache');
    }

    private function tmpPath(): string
    {
        return FileSystem::buildPath($this->root, 'tmp');
    }

    private function backupPath(): string
    {
        return FileSystem::buildPath($this->root, 'backup');
    }
}
