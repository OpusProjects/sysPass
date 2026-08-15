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

namespace SP\Tests\Integration\Application\Account;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Account\Dtos\AccountUpdateDto;
use SP\Domain\Category\Models\Category;
use SP\Domain\Client\Models\Client;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Restores a real account's history over a real database and checks that what comes back is
 * exactly what was there before — not just that the restore call answered "OK".
 *
 * AccountHistoryManagerTest (Integration, Web controller) only proves the endpoint responds with
 * the right JSON: it stubs the `UPDATE Account` statement to report one affected row and never
 * looks at what the row actually holds afterward. A restore that wrote the wrong history version,
 * or that wrote a password nothing can decrypt again, would pass that test too — the user would
 * only find out the next time they tried to use the credential.
 *
 * The container is built by hand exactly like ExportImportRoundTripTest/ImportFormatsTest do (real
 * DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler swapped
 * for a real connection), because IntegrationTestCase mocks DatabaseInterface outright and nothing
 * would actually persist to read back. 'cli' is the smallest module that leaves Context bound to
 * the plain Stateless implementation (no PHP session machinery to fake), and nothing here goes
 * through Bootstrap/Router — only the Application-layer AccountService/AccountHistoryService are
 * exercised directly, which is exactly the boundary this test is about.
 *
 * The account's own crypto is real too: the admin user's master password key is set directly on
 * Context (setTrasientKey(MASTER_PASSWORD_KEY, ...)) the same way the other Application-layer
 * integration tests and ApiTestCase's createToken() do — so the account's passwords are genuinely
 * encrypted and decrypted back with the real Crypt service.
 */
#[Group('integration')]
final class AccountHistoryRestoreTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same
     * constant ExportImportRoundTripTest/ImportFormatsTest/ApiTestCase use.
     */
    private const MASTER_PASS = '12345678900';
    private const ADMIN_USER_ID = 1;
    private const ADMIN_GROUP_ID = 1;

    private string $root;
    private string $configPath;
    private ContainerInterface $dic;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real (non-vfs), uniquely-named directory — see ExportImportRoundTripTest for why: the
        // vfs root is shared process-wide, so runtime dirs are keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-account-history-restore-' . bin2hex(random_bytes(6))
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

        $this->dic = $this->buildContainer();
        $this->pdo = getDbHandler()->getConnection();

        $context = $this->dic->get(Context::class);
        $context->initialize();
        $context->setUserData(
            new UserDto(
                id: self::ADMIN_USER_ID,
                userGroupId: self::ADMIN_GROUP_ID,
                login: 'admin',
                userGroupName: 'Admins',
                isAdminApp: true,
                isAdminAcc: true,
            )
        );
        // AccountCrypt::getPasswordEncrypted() pulls the master password from here
        // (Service::getMasterKeyFromContext()). A real login sets this after checking the master
        // password against the stored hash; done directly since this harness has no HTTP login
        // flow to drive.
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * Create -> edit (new name/url/notes and a new password, which pushes the fully-original
     * version into history) -> restoreModified() -> the account must be back to exactly what it
     * held originally, including a password that decrypts to the original plaintext. The edit that
     * was just overwritten must itself land in history, since restoring is supposed to be undoable.
     */
    public function testRestoreModifiedBringsBackOriginalValuesAndLeavesTheOverwrittenEditInHistory(): void
    {
        $accountService = $this->dic->get(AccountService::class);
        $accountHistoryService = $this->dic->get(AccountHistoryService::class);
        $crypt = $this->dic->get(CryptInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $originalPass = 'OrigPass!' . $suffix . bin2hex(random_bytes(4));
        $original = $this->createAccount('mod-' . $suffix, $originalPass);

        $newName = 'AHR Account Edited ' . $suffix;
        $newUrl = 'https://example.test/ahr-edited/' . $suffix;
        $newNotes = "edited notes\nfor " . $suffix;
        $newPass = 'NewPass!' . $suffix . bin2hex(random_bytes(4));

        // Two separate real-world actions, exactly like the "edit account" and "change password"
        // screens do: AccountService::update() never touches pass/key (see
        // AccountUseCases::update()), so changing the password is always its own call. The FIRST
        // of the two is what snapshots the fully-original account into history, regardless of
        // which one runs first — addHistory() always captures whatever is currently live.
        $accountService->update(
            $original['accountId'],
            new AccountUpdateDto(
                clientId: $original['clientId'],
                categoryId: $original['categoryId'],
                name: $newName,
                login: $original['login'],
                url: $newUrl,
                notes: $newNotes,
            )
        );
        $accountService->editPassword(
            $original['accountId'],
            new AccountUpdateDto(pass: $newPass)
        );

        // Sanity: the live account now genuinely holds the edited values, not the original ones.
        $edited = $accountService->getById($original['accountId']);
        self::assertSame($newName, $edited->getName(), 'setup: edit did not change the name');

        $editedPassItem = $accountService->getPasswordForId($original['accountId']);
        self::assertSame(
            $newPass,
            $crypt->decrypt($editedPassItem->getPass() ?? '', $editedPassItem->getKey() ?? '', self::MASTER_PASS),
            'setup: edit did not change the password'
        );

        self::assertSame(
            2,
            $this->historyRowCountFor($original['accountId']),
            'setup: update() + editPassword() should each have pushed one row into history'
        );

        // The OLDEST history row is the fully-original snapshot (see the comment above).
        $originalHistoryId = $this->oldestHistoryIdFor($original['accountId']);
        $originalHistoryDto = $accountHistoryService->getById($originalHistoryId);

        self::assertTrue($originalHistoryDto->isModify, 'the original snapshot should be a "modified" entry');
        self::assertFalse($originalHistoryDto->isDeleted, 'the original snapshot should not be a "deleted" entry');
        self::assertSame($original['name'], $originalHistoryDto->name, 'setup: history did not keep the original name');

        $accountService->restoreModified($originalHistoryDto);

        $restored = $accountService->getById($original['accountId']);
        self::assertSame($original['name'], $restored->getName(), 'name was not restored to the original');
        self::assertSame($original['login'], $restored->getLogin(), 'login was not restored to the original');
        self::assertSame($original['url'], $restored->getUrl(), 'url was not restored to the original');
        self::assertSame($original['notes'], $restored->getNotes(), 'notes were not restored to the original');

        $restoredPassItem = $accountService->getPasswordForId($original['accountId']);
        $restoredPlain = $crypt->decrypt(
            $restoredPassItem->getPass() ?? '',
            $restoredPassItem->getKey() ?? '',
            self::MASTER_PASS
        );
        self::assertSame(
            $originalPass,
            $restoredPlain,
            'password did not decrypt back to the exact original plaintext after restoring'
        );

        // restoreModified() itself calls addHistory() before applying the restore, so the edit it
        // just overwrote must now be sitting in history — restoring must stay undoable.
        self::assertSame(
            3,
            $this->historyRowCountFor($original['accountId']),
            'restoreModified() should have pushed the overwritten edit into history as a third row'
        );

        $overwrittenEditId = $this->latestHistoryIdFor($original['accountId']);
        $overwrittenEditDto = $accountHistoryService->getById($overwrittenEditId);

        self::assertSame($newName, $overwrittenEditDto->name, "the overwritten edit's name was not preserved in history");
        self::assertSame($newUrl, $overwrittenEditDto->url, "the overwritten edit's url was not preserved in history");
        self::assertSame(
            $newNotes,
            $overwrittenEditDto->notes,
            "the overwritten edit's notes were not preserved in history"
        );

        $overwrittenPlain = $crypt->decrypt(
            $overwrittenEditDto->pass ?? '',
            $overwrittenEditDto->key ?? '',
            self::MASTER_PASS
        );
        self::assertSame(
            $newPass,
            $overwrittenPlain,
            "the overwritten edit's password did not survive, decryptable, in history"
        );
    }

    /**
     * Create -> delete (which hard-deletes the row and pushes a "deleted" snapshot into history)
     * -> restoreRemoved() -> the account must exist again with its original values, including a
     * password that decrypts to the original plaintext.
     */
    public function testRestoreRemovedBringsBackTheDeletedAccountWithItsOriginalValues(): void
    {
        $accountService = $this->dic->get(AccountService::class);
        $accountHistoryService = $this->dic->get(AccountHistoryService::class);
        $crypt = $this->dic->get(CryptInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $originalPass = 'DelPass!' . $suffix . bin2hex(random_bytes(4));
        $original = $this->createAccount('del-' . $suffix, $originalPass);

        $accountService->delete($original['accountId']);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `Account` WHERE `id` = :id');
        $statement->execute(['id' => $original['accountId']]);
        self::assertSame(
            0,
            (int)$statement->fetchColumn(),
            'setup: delete() should have physically removed the account row'
        );

        self::assertSame(
            1,
            $this->historyRowCountFor($original['accountId']),
            'setup: delete() should have pushed exactly one row into history'
        );

        $deletedHistoryId = $this->oldestHistoryIdFor($original['accountId']);
        $deletedHistoryDto = $accountHistoryService->getById($deletedHistoryId);

        self::assertTrue($deletedHistoryDto->isDeleted, 'the snapshot should be a "deleted" entry');
        self::assertFalse($deletedHistoryDto->isModify, 'the snapshot should not be a "modified" entry');
        self::assertSame($original['name'], $deletedHistoryDto->name, 'setup: history did not keep the original name');

        $accountService->restoreRemoved($deletedHistoryDto);

        // restoreRemoved() inserts a brand-new row (a hard delete leaves no id to update over), so
        // the restored account must be looked up by its (unique) name rather than the old id.
        $statement = $this->pdo->prepare('SELECT `id` FROM `Account` WHERE `name` = :name');
        $statement->execute(['name' => $original['name']]);
        $restoredIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(
            1,
            $restoredIds,
            'restoring a deleted account should have re-created exactly one row with its name'
        );

        $restoredId = (int)$restoredIds[0];

        $restored = $accountService->getById($restoredId);
        self::assertSame($original['name'], $restored->getName(), 'name was not restored to the original');
        self::assertSame($original['login'], $restored->getLogin(), 'login was not restored to the original');
        self::assertSame($original['url'], $restored->getUrl(), 'url was not restored to the original');
        self::assertSame($original['notes'], $restored->getNotes(), 'notes were not restored to the original');

        $restoredPassItem = $accountService->getPasswordForId($restoredId);
        $restoredPlain = $crypt->decrypt(
            $restoredPassItem->getPass() ?? '',
            $restoredPassItem->getKey() ?? '',
            self::MASTER_PASS
        );
        self::assertSame(
            $originalPass,
            $restoredPlain,
            'password did not decrypt back to the exact original plaintext after restoring'
        );
    }

    /**
     * Creates one category, one client and one account referencing them, through the real
     * services — so history restore is exercised against exactly what a real installation would
     * have stored.
     *
     * @return array{
     *     accountId: int, clientId: int, categoryId: int,
     *     name: string, login: string, url: string, notes: string
     * }
     */
    private function createAccount(string $suffix, string $password): array
    {
        $categoryService = $this->dic->get(CategoryService::class);
        $clientService = $this->dic->get(ClientService::class);
        $accountService = $this->dic->get(AccountService::class);

        $categoryId = $categoryService->create(
            new Category(['name' => 'AHR Category ' . $suffix, 'description' => 'AHR category ' . $suffix])
        );
        $clientId = $clientService->create(
            new Client(['name' => 'AHR Client ' . $suffix, 'description' => 'AHR client ' . $suffix])
        );

        $name = 'AHR Account ' . $suffix;
        $login = 'ahr-login-' . $suffix;
        $url = 'https://example.test/ahr/' . $suffix;
        $notes = "ahr notes\nfor " . $suffix;

        $accountId = $accountService->create(
            new AccountCreateDto(
                clientId: $clientId,
                categoryId: $categoryId,
                userId: self::ADMIN_USER_ID,
                userGroupId: self::ADMIN_GROUP_ID,
                name: $name,
                login: $login,
                pass: $password,
                url: $url,
                notes: $notes,
            )
        );

        return compact('accountId', 'clientId', 'categoryId', 'name', 'login', 'url', 'notes');
    }

    private function historyRowCountFor(int $accountId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `AccountHistory` WHERE `accountId` = :id');
        $statement->execute(['id' => $accountId]);

        return (int)$statement->fetchColumn();
    }

    private function oldestHistoryIdFor(int $accountId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `AccountHistory` WHERE `accountId` = :id ORDER BY `id` ASC LIMIT 1'
        );
        $statement->execute(['id' => $accountId]);

        return (int)$statement->fetchColumn();
    }

    private function latestHistoryIdFor(int $accountId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `AccountHistory` WHERE `accountId` = :id ORDER BY `id` DESC LIMIT 1'
        );
        $statement->execute(['id' => $accountId]);

        return (int)$statement->fetchColumn();
    }

    private function buildContainer(): ContainerInterface
    {
        $_ENV['CONFIG_PATH'] = $this->configPath;

        try {
            $coreDefinitions = CoreDefinitions::getDefinitions(REAL_APP_ROOT, 'cli');
        } finally {
            unset($_ENV['CONFIG_PATH']);
        }

        // Real (non-vfs) runtime dirs, keyed to this test run — see the note on $this->root.
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
