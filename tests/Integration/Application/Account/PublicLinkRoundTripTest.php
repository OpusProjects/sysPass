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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\PublicLinkService as PublicLinkServiceInterface;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Account\Dtos\AccountViewDto;
use SP\Domain\Account\Models\PublicLink as PublicLinkModel;
use SP\Domain\Account\PublicLinkType;
use SP\Domain\Category\Models\Category;
use SP\Domain\Client\Models\Client;
use SP\Domain\Common\Adapters\Serde;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Crypt\Vault;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Crypt\Crypt;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * A public link hands out an account's password with nobody signed in: whoever holds the URL gets
 * whatever following it yields. ViewLinkRefusalsTest covers the refusals -- expired, exhausted,
 * unknown hash -- but does so against hand-built fixture links, never one the application actually
 * created. Nothing exercised PublicLinkService::create() and the unsealing ViewLinkController does
 * against each other, so a drift between the two -- a different key derivation, a changed
 * serialisation -- would leave every link an installation issues broken (or worse), with nothing
 * saying so.
 *
 * This drives the real PublicLinkService and AccountService against a real database: create an
 * account with a known password, create a public link for it exactly the way
 * SaveCreateFromAccountController does, then unseal it exactly the way ViewLinkController does --
 * PublicLinkService::getByHash(), the same two guard checks, addLinkView(), then
 * Serde::deserialize(...) into a Vault and Vault::getData($key) -- and compare the result to the
 * plaintext that went in. Followed at the service layer rather than through the HTTP/Bootstrap
 * dispatch: everything ViewLinkController does between fetching the link and rendering the page is
 * either service calls (replicated here) or template assembly (irrelevant to whether the password
 * round-trips), and IntegrationTestCase's mocked database can't stand in for a real create-then-read.
 *
 * The container is built by hand exactly like AccountHistoryRestoreTest/AccountAccessTest do (real
 * DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler swapped for
 * a real connection), for the same reason: IntegrationTestCase mocks the database away, and nothing
 * would actually persist to read back.
 */
#[Group('integration')]
final class PublicLinkRoundTripTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same constant
     * AccountHistoryRestoreTest/AccountAccessTest use.
     */
    private const MASTER_PASS = '12345678900';
    private const ADMIN_USER_ID = 1;
    private const ADMIN_GROUP_ID = 1;

    private string $root;
    private string $configPath;
    private ContainerInterface $dic;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real (non-vfs), uniquely-named directory -- see AccountHistoryRestoreTest for why: the
        // vfs root is shared process-wide, so runtime dirs are keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-public-link-roundtrip-' . bin2hex(random_bytes(6))
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
        // AccountCryptService::getPasswordEncrypted() and PublicLink::getSecuredLinkData() both pull
        // the master password from here (Service::getMasterKeyFromContext()). A real login sets this
        // after checking the master password against the stored hash; done directly since this
        // harness has no HTTP login flow to drive.
        $context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The core property: what comes back out of the link is the exact plaintext that went into the
     * account, not merely "some string" -- and the link's own view counter, which is the thing the
     * exhaustion guard reads, genuinely moved.
     */
    public function testFollowingTheLinkYieldsTheOriginalPasswordAndIncrementsTheViewCounter(): void
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $password = 'RoundTripPass!' . bin2hex(random_bytes(6));
        $accountId = $this->createAccount('rt-' . $suffix, $password);

        $hash = $this->createLinkFor($accountId);

        $beforeViews = $publicLinkService->getByHash($hash)->getCountViews();

        $yielded = $this->followLink($hash);

        self::assertNotNull($yielded, 'a link within both limits should yield the account');
        self::assertSame(
            $password,
            $yielded,
            'the password decrypted off the followed link was not the exact plaintext the account was created with'
        );

        $afterViews = $publicLinkService->getByHash($hash)->getCountViews();
        self::assertSame(
            $beforeViews + 1,
            $afterViews,
            'following the link did not increment its view counter'
        );
    }

    /**
     * Once a link has been followed as many times as its configured limit allows, following it again
     * no longer yields the account -- the limit is enforced, not decorative. Every permitted view
     * still yields the exact original password, and the counter never creeps past the limit once
     * refusals start.
     */
    public function testALinkStopsYieldingTheAccountOnceItsViewLimitIsReached(): void
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $password = 'ExhaustPass!' . bin2hex(random_bytes(6));
        $accountId = $this->createAccount('ex-' . $suffix, $password);

        $hash = $this->createLinkFor($accountId);

        $maxCountViews = $publicLinkService->getByHash($hash)->getMaxCountViews();
        self::assertGreaterThan(0, $maxCountViews, 'setup: the configured view limit must be positive to be exercised');

        for ($view = 1; $view <= $maxCountViews; $view++) {
            $yielded = $this->followLink($hash);

            self::assertSame(
                $password,
                $yielded,
                sprintf('view %d of %d (within the limit) should have yielded the account', $view, $maxCountViews)
            );
        }

        self::assertSame(
            $maxCountViews,
            $publicLinkService->getByHash($hash)->getCountViews(),
            'setup: the link should have recorded exactly one view per permitted follow'
        );

        // One more follow, past the limit.
        $refused = $this->followLink($hash);

        self::assertNull($refused, 'a link followed as many times as its limit allows should stop yielding the account');
        self::assertSame(
            $maxCountViews,
            $publicLinkService->getByHash($hash)->getCountViews(),
            'a refused follow must not itself count as a view'
        );
    }

    /**
     * A copy of the link read before it was spent cannot be used to spend it again.
     *
     * This is the concurrent case, made deterministic. Two requests arriving together each read
     * the row before either has written to it, so each holds a copy saying the link still has
     * views left — and the check used to be made against that copy:
     *
     * ```php
     * && $publicLink->getCountViews() < $publicLink->getMaxCountViews()
     * ```
     *
     * Both passed, both were served, and a link issued for a single view handed the account out
     * twice. Holding a stale copy is exactly what the second request has, so exhausting the link
     * and then presenting the copy reproduces it without needing two processes.
     *
     * The limit and the expiry are conditions of the update now, so the server is what refuses,
     * and a copy of any age cannot talk it round.
     */
    public function testAStaleCopyOfALinkCannotSpendItPastItsLimit(): void
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $accountId = $this->createAccount('race-' . $suffix, 'RacePass!' . bin2hex(random_bytes(6)));

        $hash = $this->createLinkFor($accountId);

        // The copy the second request would be holding: read before anything has been spent.
        $staleCopy = $publicLinkService->getByHash($hash);

        $maxCountViews = $staleCopy->getMaxCountViews();
        self::assertGreaterThan(0, $maxCountViews, 'setup: the configured view limit must be positive');

        for ($view = 1; $view <= $maxCountViews; $view++) {
            self::assertNotNull($this->followLink($hash), sprintf('setup: view %d should be allowed', $view));
        }

        self::assertFalse(
            $publicLinkService->addLinkView($staleCopy),
            'a copy read before the link was exhausted must not be able to spend it again'
        );

        self::assertSame(
            $maxCountViews,
            $publicLinkService->getByHash($hash)->getCountViews(),
            'the refused attempt must not have moved the counter past the limit'
        );
    }

    /**
     * The same for an expiry that passed while the request was in flight.
     *
     * The expiry was the other half of the same check, read from the same stale copy, so it is
     * refused by the same update rather than by a comparison made before it.
     */
    public function testALinkThatExpiresBeforeTheViewIsRecordedIsRefused(): void
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $accountId = $this->createAccount('exp-' . $suffix, 'ExpPass!' . bin2hex(random_bytes(6)));

        $hash = $this->createLinkFor($accountId);
        $publicLink = $publicLinkService->getByHash($hash);

        // Expire it behind the copy the request is holding, which still says it is good.
        $statement = getDbHandler()->getConnection()
                                   ->prepare('UPDATE `PublicLink` SET `dateExpire` = :expired WHERE `hash` = :hash');
        $statement->execute(['expired' => time() - 1, 'hash' => $hash]);

        self::assertGreaterThan(
            time(),
            $publicLink->getDateExpire(),
            'setup: the copy in hand must still believe the link is live'
        );

        self::assertFalse(
            $publicLinkService->addLinkView($publicLink),
            'a link that expired before the view was recorded must not be spent'
        );
    }

    /**
     * A link whose expiry has already passed does not yield the account either, even though it is
     * nowhere near its view limit. The expiry is moved into the past through
     * PublicLinkService::update() -- the same service the application uses to persist a link -- not
     * by waiting for real time to pass.
     */
    public function testAnExpiredLinkDoesNotYieldTheAccount(): void
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $password = 'ExpiredPass!' . bin2hex(random_bytes(6));
        $accountId = $this->createAccount('exp-' . $suffix, $password);

        $hash = $this->createLinkFor($accountId);

        $current = $publicLinkService->getByHash($hash);
        self::assertSame(0, $current->getCountViews(), 'setup: a freshly created link should have no views yet');
        self::assertGreaterThan(
            0,
            $current->getMaxCountViews(),
            'setup: the link must still be within its view limit -- expiry alone must be what refuses it'
        );

        $publicLinkService->update($current->mutate(['dateExpire' => time() - 100]));

        $refused = $this->followLink($hash);

        self::assertNull($refused, 'a link past its expiry date should not yield the account');
        self::assertSame(
            0,
            $publicLinkService->getByHash($hash)->getCountViews(),
            'a refused follow of an expired link must not count as a view'
        );
    }

    /**
     * Creates a public link for $accountId exactly the way
     * SaveCreateFromAccountController::saveCreateFromAccountAction() does, and returns the hash the
     * service actually stored -- PublicLinkService::create() derives its own hash internally
     * (PublicLink::buildPublicLink()) rather than keeping whatever placeholder was passed in.
     */
    private function createLinkFor(int $accountId): string
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);

        $publicLinkData = new PublicLinkModel(
            [
                'typeId' => PublicLinkType::Account->value,
                'itemId' => $accountId,
                'notify' => false,
                'hash' => bin2hex(random_bytes(16)),
            ]
        );

        $linkId = $publicLinkService->create($publicLinkData);

        return $publicLinkService->getById($linkId)->getHash();
    }

    /**
     * Follows a public link exactly the way ViewLinkController::viewLinkAction() does: fetch by
     * hash, apply the same two guard checks (not yet expired, still under its view limit), record
     * the view, then unseal the vault the same way -- Serde::deserialize() into a Vault,
     * Vault::getData() with the link's own key, Serde::deserialize() the result into a Simple model,
     * and AccountViewDto::fromModel() over that. Returns the decrypted password, or null when either
     * guard refuses the link -- matching the controller showing the refusal page instead of the
     * account in that case.
     */
    private function followLink(string $hash): ?string
    {
        $publicLinkService = $this->dic->get(PublicLinkServiceInterface::class);
        $accountService = $this->dic->get(AccountService::class);

        $publicLink = $publicLinkService->getByHash($hash);

        // Spending the view is the guard, exactly as the controller now has it: the expiry and the
        // limit are conditions of the update, so this answers whether there was a view left to
        // take. It used to be a pair of comparisons here, against the row just read.
        if (!$publicLinkService->addLinkView($publicLink)) {
            return null;
        }

        $accountService->incrementViewCounter($publicLink->getItemId());
        $accountService->incrementDecryptCounter($publicLink->getItemId());

        /** @var Vault $vault The stored blob is a Vault; Serde answers with whatever it holds. */
        $vault = Serde::deserialize($publicLink->getData() ?? '', Vault::class, Crypt::class);

        $accountViewDto = AccountViewDto::fromModel(
            Serde::deserialize(
                $vault->getData($publicLinkService->getPublicLinkKey($publicLink->getHash())->getKey()),
                Simple::class
            )
        );

        return $accountViewDto->getPass();
    }

    /**
     * Creates one category, one client and one account referencing them, through the real services
     * -- so the round trip is exercised against exactly what a real installation would have stored.
     */
    private function createAccount(string $suffix, string $password): int
    {
        $categoryService = $this->dic->get(CategoryService::class);
        $clientService = $this->dic->get(ClientService::class);
        $accountService = $this->dic->get(AccountService::class);

        $categoryId = $categoryService->create(
            new Category(['name' => 'PLRT Category ' . $suffix, 'description' => 'PLRT category ' . $suffix])
        );
        $clientId = $clientService->create(
            new Client(['name' => 'PLRT Client ' . $suffix, 'description' => 'PLRT client ' . $suffix])
        );

        return $accountService->create(
            new AccountCreateDto(
                clientId: $clientId,
                categoryId: $categoryId,
                userId: self::ADMIN_USER_ID,
                userGroupId: self::ADMIN_GROUP_ID,
                name: 'PLRT Account ' . $suffix,
                login: 'plrt-login-' . $suffix,
                pass: $password,
                url: 'https://example.test/plrt/' . $suffix,
                notes: "plrt notes\nfor " . $suffix,
            )
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

        // Real (non-vfs) runtime dirs, keyed to this test run -- see the note on $this->root.
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
