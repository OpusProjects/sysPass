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

namespace SP\Tests\Integration\Application\Import;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Import\Ports\ImportService;
use SP\Application\Import\Ports\ItemsImportService;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\Import\Dtos\ImportParamsDto;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Infrastructure\File\FileHandler;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Runs the real CSV and KeePass importers against the shipped fixtures
 * (tests/res/import/data.csv, tests/res/import/data_keepass.xml) through a real database, and
 * checks that the accounts those files describe are actually there afterward — reachable by
 * name, with the right client/category, and with a password that decrypts back to the plaintext
 * the fixture carries.
 *
 * Neither existing suite establishes that: ConfigImportTest (Integration) drives the endpoint
 * through IntegrationTestCase, which mocks DatabaseInterface outright, so it can only assert the
 * literal JSON response string ("Import finished") — nothing it does actually persists. And
 * CsvImportTest / KeepassImportTest (Unit) mock AccountService/CategoryService/ClientService, so
 * they assert what was handed to create() calls, never that anything was stored or can be read
 * back. An importer that silently dropped every account, or stored a password that can never be
 * decrypted again, would pass both of those today.
 *
 * The container is built by hand exactly like ExportImportRoundTripTest/CliTestCase/ApiTestCase
 * do (real DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with
 * DbStorageHandler swapped for a real connection) for the same reason: IntegrationTestCase mocks
 * the database away, so accountService->create() would never actually persist a row to read
 * back. 'cli' is the smallest module that leaves Context bound to the plain Stateless
 * implementation (no PHP session machinery to fake), and nothing here goes through
 * Bootstrap/Router — only the Application-layer ImportService is exercised directly.
 *
 * The fixture files are read straight off the real (non-vfs) project tree
 * (REAL_APP_ROOT/tests/res/import/...) rather than through vfsStream: the importers detect the
 * file format via mime_content_type() and, for XML, DOMDocument::load() — both need a real path
 * on disk, and they only ever read the fixtures, never write them.
 */
#[Group('integration')]
final class ImportFormatsTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same
     * constant ExportImportRoundTripTest/ApiTestCase use.
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

        // A real (non-vfs), uniquely-named directory — see ExportImportRoundTripTest for why:
        // the vfs root is shared process-wide, so runtime dirs are keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-import-formats-' . bin2hex(random_bytes(6))
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
        // (Service::getMasterKeyFromContext()) — a real login sets this after checking the
        // master password against the stored hash; done directly since this harness has no HTTP
        // login flow to drive.
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The CSV fixture has four logical rows (SplFileObject::fgetcsv() confirms it: no trailing
     * blank line in this particular file, unlike the scenario CsvImportTest's own unit test
     * simulates), one of them — "Test CSV 1" — repeated verbatim. Two rows ("Google"/"Linux" and
     * "Apple"/"SSH") name a client and category that already exist in the seeded database, so
     * this also exercises addClient()/addCategory() reusing an existing row by name rather than
     * creating a duplicate.
     */
    public function testCsvImportPersistsAccountsWithDecryptablePasswords(): void
    {
        $itemsImportService = $this->import($this->fixturePath('data.csv'));

        self::assertSame(
            4,
            $itemsImportService->getCounter(),
            'all four CSV rows — including the repeated one — should be imported; none silently dropped'
        );

        $duplicateIds = $this->accountIdsByName('Test CSV 1');
        self::assertCount(
            2,
            $duplicateIds,
            'the CSV lists "Test CSV 1" twice; import never dedupes accounts by name, so both rows land'
        );
        foreach ($duplicateIds as $id) {
            $this->assertAccountFields(
                $id,
                client: 'CSV Client 1',
                category: 'CSV Category 1',
                login: 'csv_login1',
                url: 'http://test.me',
                notes: 'CSV Notes',
                pass: 'csv_pass1'
            );
        }

        // This row's Notes field spans several physical CSV lines inside one quoted field —
        // proves the embedded newlines were read as part of the field, not as separate rows.
        $multilineIds = $this->accountIdsByName('Test CSV 2');
        self::assertCount(1, $multilineIds);
        $this->assertAccountFields(
            $multilineIds[0],
            client: 'Google',
            category: 'Linux',
            login: 'csv_login2',
            url: 'http://linux.org',
            notes: "CSV Notes 2\nbla\nbla\ncar\n",
            pass: 'csv_pass2'
        );

        $thirdIds = $this->accountIdsByName('Test CSV 3');
        self::assertCount(1, $thirdIds);
        $this->assertAccountFields(
            $thirdIds[0],
            client: 'Apple',
            category: 'SSH',
            login: 'csv_login2',
            url: 'http://apple.com',
            notes: 'CSV Notes 3',
            pass: 'csv_pass3'
        );
    }

    /**
     * KeePass's <Group> tree becomes categories (every group, not only the ones an entry
     * actually references), all entries land under a single "KeePass" client, and the entry
     * nested inside another entry's <History> must not surface as an account of its own.
     */
    public function testKeepassImportPersistsAccountsWithDecryptablePasswords(): void
    {
        $itemsImportService = $this->import($this->fixturePath('data_keepass.xml'));

        self::assertSame(
            5,
            $itemsImportService->getCounter(),
            'five leaf entries in the fixture; the one nested in <History> under "proxy" must not be counted'
        );

        $expectedAccounts = [
            [
                'name' => 'Sample Entry',
                'category' => 'NewDatabase',
                'login' => 'User Name',
                'url' => 'https://keepass.info/',
                'notes' => 'Notes',
                'pass' => 'Password',
            ],
            [
                'name' => 'Sample Entry #2',
                'category' => 'NewDatabase',
                'login' => 'Michael321',
                'url' => 'https://keepass.info/help/kb/testform.html',
                'notes' => '',
                'pass' => '12345',
            ],
            [
                'name' => 'DC1',
                'category' => 'Windows',
                'login' => 'administrator',
                'url' => '192.168.100.1',
                'notes' => 'ADS server',
                'pass' => 'k6V4iIAeR9SBOprLMUGV',
            ],
            [
                'name' => 'debian',
                'category' => 'Linux',
                'login' => 'root',
                'url' => 'http://debian.org',
                'notes' => 'Some notes about the server',
                'pass' => 'TKr321zqCZhgbzmmAX13',
            ],
            [
                'name' => 'proxy',
                'category' => 'Linux',
                'login' => 'admin',
                'url' => '192.168.0.1',
                'notes' => 'Some notes about proxy server',
                'pass' => 'TKr321zqCZhgbzmmAX13',
            ],
        ];

        foreach ($expectedAccounts as $expected) {
            $ids = $this->accountIdsByName($expected['name']);

            // For "debian" specifically, this also proves the entry nested inside "proxy"'s
            // <History> (an older revision, also titled "debian") was excluded rather than
            // imported as a second account.
            self::assertCount(
                1,
                $ids,
                sprintf('exactly one account named "%s" should exist', $expected['name'])
            );

            $this->assertAccountFields(
                $ids[0],
                client: 'KeePass',
                category: $expected['category'],
                login: $expected['login'],
                url: $expected['url'],
                notes: $expected['notes'],
                pass: $expected['pass']
            );
        }

        // Every <Group><Name> in the fixture becomes a category, including groups with no
        // entries of their own (e.g. "Homebanking"). "Windows" appears as two distinct <Group>
        // elements (nested under Servers, and again at the top level) and must still resolve to
        // one category, not two, and without a DuplicatedItemException blowing up the import.
        foreach (
            [
                'NewDatabase',
                'General',
                'Servers',
                'Windows',
                'Linux',
                'Network',
                'Internet',
                'eMail',
                'Homebanking',
            ] as $categoryName
        ) {
            self::assertSame(
                1,
                $this->categoryCountByName($categoryName),
                sprintf('category "%s" should exist exactly once', $categoryName)
            );
        }
    }

    private function fixturePath(string $file): string
    {
        return FileSystem::buildPath(REAL_APP_ROOT, 'tests', 'res', 'import', $file);
    }

    private function import(string $file): ItemsImportService
    {
        $importService = $this->dic->get(ImportService::class);

        return $importService->doImport(
            new ImportParamsDto(
                file: new FileHandler($file),
                defaultUser: self::ADMIN_USER_ID,
                defaultGroup: self::ADMIN_GROUP_ID,
                masterPassword: self::MASTER_PASS,
            )
        );
    }

    /**
     * @return int[]
     */
    private function accountIdsByName(string $name): array
    {
        $statement = $this->pdo->prepare('SELECT `id` FROM `Account` WHERE `name` = :name');
        $statement->execute(['name' => $name]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function categoryCountByName(string $name): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `Category` WHERE `name` = :name');
        $statement->execute(['name' => $name]);

        return (int)$statement->fetchColumn();
    }

    /**
     * Checks the account's client/category/login/url/notes by name, and — the assertion that
     * matters most — that its password decrypts back to the fixture's plaintext. A password
     * that stores but can never be read again is the failure mode worth catching; asserting only
     * that a row exists would miss it entirely.
     */
    private function assertAccountFields(
        int $id,
        string $client,
        string $category,
        string $login,
        string $url,
        string $notes,
        string $pass
    ): void {
        $accountService = $this->dic->get(AccountService::class);
        $account = $accountService->getByIdEnriched($id);

        self::assertSame($client, $account->getClientName(), "client for account #$id did not match");
        self::assertSame($category, $account->getCategoryName(), "category for account #$id did not match");
        self::assertSame($login, $account->getLogin(), "login for account #$id did not match");
        self::assertSame($url, $account->getUrl(), "url for account #$id did not match");
        self::assertSame($notes, $account->getNotes(), "notes for account #$id did not match");

        $passItem = $accountService->getPasswordForId($id);
        $crypt = $this->dic->get(CryptInterface::class);
        $decrypted = $crypt->decrypt($passItem->getPass() ?? '', $passItem->getKey() ?? '', self::MASTER_PASS);

        self::assertSame($pass, $decrypted, "password for account #$id did not decrypt back to the original");
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
