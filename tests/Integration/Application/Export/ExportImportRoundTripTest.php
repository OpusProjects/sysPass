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

namespace SP\Tests\Integration\Application\Export;

use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\AccountToTagService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\Export\Ports\XmlExportService;
use SP\Application\Import\Ports\ImportService;
use SP\Application\Tag\Ports\TagService;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Category\Models\Category;
use SP\Domain\Client\Models\Client;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\Import\Dtos\ImportParamsDto;
use SP\Domain\Tag\Models\Tag;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Infrastructure\File\DirectoryHandler;
use SP\Infrastructure\File\FileHandler;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * Exports a real account (plus the category, client and tags it references) and imports the
 * resulting XML file straight back, then checks that what comes out is what went in — not just
 * that the round trip completed without an exception.
 *
 * This is the property neither existing suite covers on its own: XmlExportTest (Unit) mocks every
 * XmlXxxExportService, and the Import/Services unit tests mock AccountService/CategoryService/etc.
 * Both are legitimate in isolation, but neither can catch the two halves silently drifting apart —
 * e.g. the exporter renaming an XML tag the importer still reads under the old name.
 *
 * It runs against the Integration suite's real database (IntegrationTestCase mocks DatabaseInterface
 * outright — accountService->create() would never actually persist a row to read back). The
 * container is built by hand exactly like CliTestCase/ApiTestCase do (real DomainDefinitions +
 * CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler swapped for the real
 * connection): the 'cli' module is the smallest one that leaves Context bound to the plain
 * Stateless implementation (no PHP session machinery to fake), and nothing here goes through
 * Bootstrap/Router — only the Application-layer Export/Import services are exercised directly,
 * which is exactly the boundary this test is about.
 *
 * The account's own crypto is real too: the admin user's master password key is set directly on
 * Context (setTrasientKey(MASTER_PASSWORD_KEY, ...)) the same way ApiTestCase's createToken() does
 * for AccountCryptService — so the account's password is genuinely encrypted, exported, decrypted
 * by the importer and re-encrypted, and can be decrypted back to the original plaintext afterward.
 */
#[Group('integration')]
final class ExportImportRoundTripTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same
     * constant ApiTestCase uses, and AccountControllerTest::testViewPassAction() already proves
     * it round-trips a real account password through create + decrypt.
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

        // A real (non-vfs), uniquely-named directory: the exported file is written by
        // DOMDocument::save() and read back by a real FileHandler/DOMDocument::load(), and the
        // vfs root is shared process-wide, so this is keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-export-import-rt-' . bin2hex(random_bytes(6))
        );
        $this->configPath = FileSystem::buildPath($this->root, 'config');

        foreach ([$this->configPath, $this->cachePath(), $this->tmpPath(), $this->exportPath()] as $dir) {
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
        // AccountCrypt::getPasswordEncrypted() and ImportBase::addAccount() both pull the master
        // password from here (Service::getMasterKeyFromContext()). A real login sets this after
        // checking the master password against the stored hash; done directly since this harness
        // has no HTTP login flow to drive.
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The core property: an unencrypted export, read back by the importer, produces an account
     * — plus the category, client and tags it references — identical to what was exported.
     */
    public function testUnencryptedExportRoundTrips(): void
    {
        $dataset = $this->createDataset('plain');

        $file = $this->export();
        $this->import($file);

        $this->assertAccountRoundTripped($dataset);
    }

    /**
     * And the encrypted export, which is the one an operator actually keeps: it round-trips given
     * its password.
     *
     * This is the test that found why it did not. The decrypted fragment used to be parsed into a
     * document that had not been told to drop whitespace — unlike the one XmlFile builds for the
     * file itself — and the export is written pretty-printed, so the indentation arrived as text
     * nodes and was grafted into the main tree. The first one reached a callback typed for
     * elements, and the TypeError that raised is an Error, so nothing on the way out caught it:
     * not the importer's own catch, not the transaction's. An encrypted backup did not fail to
     * restore with an error — it failed with a PHP fatal, for any export containing so much as one
     * category.
     */
    public function testEncryptedExportRoundTripsWithTheRightPassword(): void
    {
        $dataset = $this->createDataset('enc');
        $exportPassword = 'RtExportPass!' . bin2hex(random_bytes(4));

        $file = $this->export($exportPassword);

        $this->import($file, $exportPassword);

        $this->assertAccountRoundTripped($dataset);
    }

    /**
     * ...and fails cleanly with the wrong one: no account is left behind half-imported, and the
     * failure is reported rather than silently importing garbage.
     */
    public function testEncryptedExportFailsCleanlyWithTheWrongPassword(): void
    {
        $dataset = $this->createDataset('wrong');
        $exportPassword = 'RtExportPass!' . bin2hex(random_bytes(4));

        $file = $this->export($exportPassword);

        try {
            $this->import($file, 'definitely-the-wrong-password');
            self::fail('Import with the wrong password should have thrown');
        } catch (ServiceException $e) {
            // Import::doImport() wraps everything in Repository::transactionAware(), which
            // rethrows as ServiceException — see BaseRepository::transactionAware() — but
            // preserves the original message.
            self::assertStringContainsString('Wrong encryption password', $e->getMessage());
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM `Account` WHERE `name` = :name');
        $statement->execute(['name' => $dataset['accountName']]);

        self::assertSame(
            1,
            (int)$statement->fetchColumn(),
            'A failed import must not leave a partially-imported account behind'
        );
    }

    /**
     * Creates one category, one client, two tags and one account referencing all of them —
     * through the real services, so the export sees exactly what a real installation would.
     *
     * @return array{
     *     categoryId: int, categoryName: string,
     *     clientId: int, clientName: string,
     *     tag1Id: int, tag1Name: string,
     *     tag2Id: int, tag2Name: string,
     *     accountId: int, accountName: string, accountLogin: string,
     *     accountUrl: string, accountNotes: string, accountPass: string
     * }
     */
    private function createDataset(string $suffix): array
    {
        $categoryService = $this->dic->get(CategoryService::class);
        $clientService = $this->dic->get(ClientService::class);
        $tagService = $this->dic->get(TagService::class);
        $accountService = $this->dic->get(AccountService::class);

        // Everything a vault is allowed to be named that XML is not: the characters the document
        // has to escape, and text outside ASCII. An export is how somebody moves their vault, so a
        // name that comes back mangled is silent data loss — and it would be invisible to a fixture
        // named only in letters and digits.
        $categoryName = 'RT Category & <Special> ' . $suffix;
        $clientName = 'RT Client "quoted" & \'apostrophe\' ' . $suffix;
        $tag1Name = 'RT Tag <One> ' . $suffix;
        $tag2Name = 'RT Tág Two ☕ ' . $suffix;

        $categoryId = $categoryService->create(
            new Category(['name' => $categoryName, 'description' => 'RT category description ' . $suffix])
        );
        $clientId = $clientService->create(
            new Client(['name' => $clientName, 'description' => 'RT client description ' . $suffix])
        );
        $tag1Id = $tagService->create(new Tag(['name' => $tag1Name]));
        $tag2Id = $tagService->create(new Tag(['name' => $tag2Name]));

        $accountName = 'RT Account & <Co> "Ltd" ' . $suffix;
        $accountLogin = 'rt-login-' . $suffix;
        $accountUrl = 'https://example.test/' . $suffix . '?a=1&b=2';
        $accountNotes = "roundtrip notes\nfor <b>" . $suffix . "</b> & more — ünïcode";
        $accountPass = 'RtPass!' . $suffix . bin2hex(random_bytes(4));

        $accountId = $accountService->create(
            new AccountCreateDto(
                clientId: $clientId,
                categoryId: $categoryId,
                userId: self::ADMIN_USER_ID,
                userGroupId: self::ADMIN_GROUP_ID,
                name: $accountName,
                login: $accountLogin,
                pass: $accountPass,
                url: $accountUrl,
                notes: $accountNotes,
                tags: [$tag1Id, $tag2Id],
            )
        );

        return compact(
            'categoryId',
            'categoryName',
            'clientId',
            'clientName',
            'tag1Id',
            'tag1Name',
            'tag2Id',
            'tag2Name',
            'accountId',
            'accountName',
            'accountLogin',
            'accountUrl',
            'accountNotes',
            'accountPass'
        );
    }

    private function export(?string $password = null): string
    {
        $xmlExportService = $this->dic->get(XmlExportService::class);

        return $xmlExportService->export(new DirectoryHandler($this->exportPath()), $password);
    }

    private function import(string $file, ?string $filePassword = null): void
    {
        $importService = $this->dic->get(ImportService::class);

        $importService->doImport(
            new ImportParamsDto(
                file: new FileHandler($file),
                defaultUser: self::ADMIN_USER_ID,
                defaultGroup: self::ADMIN_GROUP_ID,
                password: $filePassword,
                masterPassword: self::MASTER_PASS,
            )
        );
    }

    /**
     * Import never updates an existing account: SyspassImport::processAccounts() always creates a
     * new row (the category/client/tag nodes are the ones that get deduplicated by name). So the
     * export/import round trip leaves the ORIGINAL account plus a new one with the same name — the
     * property under test is that the new one matches the original field for field.
     *
     * @param array{
     *     categoryId: int, categoryName: string,
     *     clientId: int, clientName: string,
     *     tag1Id: int, tag1Name: string,
     *     tag2Id: int, tag2Name: string,
     *     accountId: int, accountName: string, accountLogin: string,
     *     accountUrl: string, accountNotes: string, accountPass: string
     * } $dataset
     */
    private function assertAccountRoundTripped(array $dataset): void
    {
        $statement = $this->pdo->prepare('SELECT `id` FROM `Account` WHERE `name` = :name AND `id` != :id');
        $statement->execute(['name' => $dataset['accountName'], 'id' => $dataset['accountId']]);
        $importedIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(
            1,
            $importedIds,
            'Import should have created exactly one new account with the exported name'
        );

        $importedId = (int)$importedIds[0];

        $accountService = $this->dic->get(AccountService::class);
        $imported = $accountService->getByIdEnriched($importedId);

        self::assertSame($dataset['accountName'], $imported->getName(), 'name did not round-trip');
        self::assertSame($dataset['accountLogin'], $imported->getLogin(), 'login did not round-trip');
        self::assertSame($dataset['accountUrl'], $imported->getUrl(), 'url did not round-trip');
        self::assertSame($dataset['accountNotes'], $imported->getNotes(), 'notes did not round-trip');
        self::assertSame(
            $dataset['categoryName'],
            $imported->getCategoryName(),
            'category (by name) did not round-trip'
        );
        self::assertSame($dataset['clientName'], $imported->getClientName(), 'client (by name) did not round-trip');

        $accountToTagService = $this->dic->get(AccountToTagService::class);
        $tagNames = array_map(
            static fn($tag) => $tag->getName(),
            $accountToTagService->getTagsByAccountId($importedId)
        );
        sort($tagNames);

        $expectedTagNames = [$dataset['tag1Name'], $dataset['tag2Name']];
        sort($expectedTagNames);

        self::assertSame($expectedTagNames, $tagNames, 'tags did not round-trip');

        // The account's password is genuinely re-encrypted by the import (decrypted with the
        // master password, then re-encrypted under a fresh key) — decrypting the result must
        // still yield the exact original plaintext.
        $passItem = $accountService->getPasswordForId($importedId);
        $crypt = $this->dic->get(CryptInterface::class);
        $decrypted = $crypt->decrypt($passItem->getPass() ?? '', $passItem->getKey() ?? '', self::MASTER_PASS);

        self::assertSame($dataset['accountPass'], $decrypted, 'password did not round-trip');
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
                Path::BACKUP => [Path::BACKUP, $this->exportPath()],
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

    private function exportPath(): string
    {
        return FileSystem::buildPath($this->root, 'export');
    }
}
