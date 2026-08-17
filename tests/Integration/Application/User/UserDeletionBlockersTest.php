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

namespace SP\Tests\Integration\Application\User;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * The user view answers "what would break if this user were removed", and it has to agree with
 * what actually happens when somebody presses delete.
 *
 * Six foreign keys reference `User` with no `ON DELETE`, which is `RESTRICT`, so any of them stops
 * the removal: `Account.userId` / `userEditId`, `AccountHistory.userId` / `userEditId`,
 * `Notification.userId` and `PublicLink.userId`. `getUsageForUser()` covered the accounts and the
 * public links — and, informatively, the account and group memberships, which cascade away and
 * block nothing — but not the account history and not the notifications.
 *
 * That is the disagreement worth pinning. An administrator retiring somebody opens their user,
 * reads a panel that lists nothing preventing the removal, presses delete and is refused, because
 * the person once edited an account or has a single unread notification. Neither is visible
 * anywhere, and neither is something the administrator can act on without being told about it.
 *
 * This runs against a real database, with the container built by hand the way
 * `PasswordResetFlowTest` builds one: `IntegrationTestCase` mocks the database away, so a test
 * that inserts a history row and then asks whether it is reported would be asking a mock that
 * answers whatever it was told to. The delete assertions in particular are only meaningful against
 * a server that enforces the constraint.
 */
#[Group('integration')]
final class UserDeletionBlockersTest extends TestCase
{
    use DatabaseTrait;

    private const MASTER_PASS = '12345678900';
    private const GROUP_ID = 1;

    private string $root;
    private string $configPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real, uniquely-named directory: the vfs root is shared process-wide, so the runtime
        // directories are keyed to this run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-user-blockers-' . bin2hex(random_bytes(6))
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
     * A user who appears only in an account's history is reported as being in use — and really
     * cannot be deleted.
     *
     * The two halves are asserted together on purpose. Either one alone can pass while the feature
     * is broken: the panel could list something that does not block, or the delete could be blocked
     * by something the panel never mentions. That second case is exactly what this fixes.
     */
    #[Test]
    public function anAccountsHistoryKeepsAUserAndTheViewSaysSo(): void
    {
        $userId = $this->createUser();
        $accountId = $this->giveTheUserAHistoryRow($userId);

        $usage = $this->usageFor($userId);

        self::assertContains(
            'AccountHistory',
            array_column($usage, 'ref'),
            'the user is named in an account history row, which blocks their removal, and the '
            . 'panel that lists what would break does not mention it'
        );

        $entry = self::entryFor($usage, 'AccountHistory');

        self::assertSame($accountId, (int)$entry['id'], 'the entry must point at the account it came from');
        self::assertNotEmpty($entry['name'], 'an entry with no name tells the administrator nothing');

        $this->assertTheDeleteIsRefused($userId);
    }

    /**
     * The same for a notification, which is the cheaper way to become undeletable: one unread
     * message is enough, and nothing about a notification suggests it is holding an account open.
     */
    #[Test]
    public function aNotificationKeepsAUserAndTheViewSaysSo(): void
    {
        $userId = $this->createUser();
        $notificationId = $this->giveTheUserANotification($userId);

        $usage = $this->usageFor($userId);

        self::assertContains(
            'Notification',
            array_column($usage, 'ref'),
            'a single notification blocks the removal and the panel does not mention it'
        );

        $entry = self::entryFor($usage, 'Notification');

        self::assertSame($notificationId, (int)$entry['id']);
        self::assertSame('Test Component', $entry['name'], 'the entry names the notification component');

        $this->assertTheDeleteIsRefused($userId);
    }

    /**
     * An account edited many times is one entry, not one per revision.
     *
     * The history keeps a row for every change ever made to an account, so without the grouping a
     * long-lived account fills the panel with entries that all say the same thing.
     */
    #[Test]
    public function anAccountEditedRepeatedlyIsListedOnce(): void
    {
        $userId = $this->createUser();
        $accountId = $this->giveTheUserAHistoryRow($userId);

        $this->giveTheUserAHistoryRow($userId, $accountId);
        $this->giveTheUserAHistoryRow($userId, $accountId);

        $entries = array_filter($this->usageFor($userId), static fn(array $e) => $e['ref'] === 'AccountHistory');

        self::assertCount(1, $entries, 'three revisions of one account must be one entry, not three');
    }

    /**
     * A user nothing refers to is reported as unused, and really can be deleted.
     *
     * The counterpart to the tests above: a panel that reported everybody as blocked would satisfy
     * them all and be useless.
     */
    #[Test]
    public function aUserNothingRefersToIsNotReportedAndCanBeDeleted(): void
    {
        $userId = $this->createUser();

        self::assertSame([], $this->usageFor($userId), 'nothing refers to this user');

        $this->buildContainer()->get(UserService::class)->delete($userId);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `User` WHERE `id` = :id');
        $statement->execute(['id' => $userId]);

        self::assertSame(0, (int)$statement->fetchColumn(), 'the user must actually be gone');
    }

    /**
     * @return array<int, array{ref: string, name: string, id: int}>
     */
    private function usageFor(int $userId): array
    {
        return array_map(
            static fn(object $row) => ['ref' => $row->ref, 'name' => (string)$row->name, 'id' => (int)$row->id],
            $this->buildContainer()->get(UserService::class)->getUsageForUser($userId)
        );
    }

    /**
     * @param array<int, array{ref: string, name: string, id: int}> $usage
     *
     * @return array{ref: string, name: string, id: int}
     */
    private static function entryFor(array $usage, string $ref): array
    {
        foreach ($usage as $entry) {
            if ($entry['ref'] === $ref) {
                return $entry;
            }
        }

        self::fail(sprintf('No %s entry in the usage list', $ref));
    }

    private function assertTheDeleteIsRefused(int $userId): void
    {
        try {
            $this->buildContainer()->get(UserService::class)->delete($userId);
        } catch (ConstraintException) {
            return;
        }

        self::fail('The database was expected to refuse the delete, and did not');
    }

    /**
     * A history row naming this user as its author, on a new account id unless one is given.
     */
    private function giveTheUserAHistoryRow(int $userId, ?int $accountId = null): int
    {
        $accountId ??= random_int(100000, 999999);

        $statement = $this->pdo->prepare(
            'INSERT INTO `AccountHistory` (`accountId`, `userId`, `userGroupId`, `userEditId`, `name`,
                    `login`, `pass`, `key`, `notes`, `mPassHash`, `categoryId`, `clientId`,
                    `dateAdd`, `isModify`, `isDeleted`)
             VALUES (:accountId, :userId, :groupId, :userId2, :name, :login, :pass, :key, :notes,
                    :mPassHash, :categoryId, :clientId, NOW(), 0, 0)'
        );

        $statement->execute(
            [
                'accountId' => $accountId,
                'userId' => $userId,
                'groupId' => self::GROUP_ID,
                'userId2' => $userId,
                'name' => 'Retired Account',
                'login' => 'someone',
                'pass' => '',
                'key' => '',
                'notes' => '',
                'mPassHash' => '',
                'categoryId' => $this->anExistingId('Category'),
                'clientId' => $this->anExistingId('Client'),
            ]
        );

        return $accountId;
    }

    private function giveTheUserANotification(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `Notification` (`type`, `component`, `description`, `date`, `checked`, `userId`)
             VALUES (:type, :component, :description, :date, 0, :userId)'
        );

        $statement->execute(
            [
                'type' => 'Test',
                'component' => 'Test Component',
                'description' => 'Something happened',
                'date' => time(),
                'userId' => $userId,
            ]
        );

        return (int)$this->pdo->lastInsertId();
    }

    private function anExistingId(string $table): int
    {
        $id = $this->pdo->query(sprintf('SELECT `id` FROM `%s` ORDER BY `id` LIMIT 1', $table))?->fetchColumn();

        self::assertNotFalse($id, sprintf('The fixtures provide no %s to attach history to', $table));

        return (int)$id;
    }

    private function createUser(): int
    {
        $dic = $this->buildContainer();

        // Kept short on purpose: UserProfile.name is varchar(45).
        $profileId = $dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'Blockers ' . bin2hex(random_bytes(4))]))
                ->dehydrate(new ProfileData(['accView' => true]))
        );

        return $dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'Deletion Blockers Test User',
                    'login' => 'blockers_' . bin2hex(random_bytes(4)),
                    'userGroupId' => self::GROUP_ID,
                    'userProfileId' => $profileId,
                    'isAdminApp' => false,
                    'isAdminAcc' => false,
                    'isDisabled' => false,
                    'isChangePass' => false,
                    'isLdap' => false,
                ]
            ),
            'Blockers!' . bin2hex(random_bytes(4)),
            self::MASTER_PASS
        );
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
