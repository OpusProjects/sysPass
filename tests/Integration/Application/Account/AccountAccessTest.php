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
use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountSearchService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Account\Dtos\AccountAclDto;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Account\Dtos\AccountSearchFilterDto;
use SP\Domain\Category\Models\Category;
use SP\Domain\Client\Models\Client;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
use SP\Domain\User\Models\UserProfile as UserProfileModel;
use SP\Infrastructure\Definitions\CoreDefinitions;
use SP\Infrastructure\Definitions\DomainDefinitions;
use SP\Tests\Support\DatabaseTrait;

use function SP\Tests\getDbHandler;
use function SP\Tests\getResource;

/**
 * The one property a password manager exists to provide: an account is readable by its owner, by
 * whoever it is explicitly shared with, and by administrators -- and by nobody else. Every other
 * account-related integration test either logs in as the fixture admin (who bypasses every
 * per-account check) or has the ACL stubbed outright: IntegrationTestCase hands every account a
 * permission with no show-flags at all, so nothing built on it can tell a real grant from a real
 * refusal. AccountAclTest exercises SP\Application\Account\Services\AccountAcl in isolation, against
 * hand-built AccountAclDto fixtures it constructs itself -- never against a real account, a real
 * owner/sharing row, or a real second user asking to read something that was never shared with them.
 *
 * This drives the real AccountAcl, the real AccountService, and the real AccountSearch service
 * against a real database, switching which user is "logged in" between assertions, to prove the
 * refusal is real and not just untested.
 *
 * Reading a password goes through a different path than listing accounts, so both are covered
 * separately here:
 *  - By id: SP\Application\Account\Services\Account::getPasswordForId() delegates straight to
 *    SP\Infrastructure\Adapter\Out\Account\Repositories\Account::getPasswordForId(), which scopes its
 *    query through AccountFilterBuilder::buildFilter() (owner / secondary user / secondary group /
 *    the user's own main-group match) before matching on the requested id -- an id outside that
 *    scope simply matches zero rows, which surfaces as the same NoSuchItemException a nonexistent id
 *    would.
 *  - Listing: SP\Application\Account\Services\AccountSearch::getByFilter() goes through
 *    SP\Infrastructure\Adapter\Out\Account\Repositories\AccountSearch, which applies the very same
 *    AccountFilterBuilder::buildFilter() to the search view before any of the user's own search
 *    terms are applied.
 * Both of those are compared against SP\Application\Account\Services\AccountAcl::getAcl(), the
 * object-level gate the view/edit/delete controllers evaluate via AccountHelper::checkAccess() /
 * AccountAclEnforcer::checkAccountAccess() once an account's data has already been fetched, so a
 * "denied" verdict here is asserted against the actual production computation, not a private
 * re-derivation of it.
 *
 * The container is built by hand exactly like AccountHistoryRestoreTest/BruteForceTrackingTest do
 * (real DomainDefinitions + CoreDefinitions('cli') + the Cli module.php, with DbStorageHandler
 * swapped for a real connection), because IntegrationTestCase mocks the database away and stubs
 * AccountAclService outright -- neither leaves anything real to test here. 'cli' keeps Context bound
 * to the plain Stateless implementation, so "logging in" as a different user between assertions is
 * just Context::setUserData() with a different UserDto, the same technique those two tests use.
 * Every user profile bit is left unset throughout (Context::setUserProfile() is never called): every
 * assertion here goes through AccountAclService/AccountService directly rather than through
 * AclInterface::checkUserAccess(), and AccountAcl::compileAccountAccess() -- the half that decides
 * resultView/resultEdit, i.e. what actually gates reading an account -- never consults the profile at
 * all. A profile row still has to exist for each created user purely to satisfy User.userProfileId's
 * foreign key; its bits are never read.
 */
#[Group('integration')]
final class AccountAccessTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same constant
     * AccountHistoryRestoreTest/ImportFormatsTest/ApiTestCase use.
     */
    private const MASTER_PASS = '12345678900';
    private const ADMIN_USER_ID = 1;
    private const ADMIN_GROUP_ID = 1;

    private string $root;
    private string $configPath;
    private ContainerInterface $dic;
    private Context $context;
    private int $clientId;
    private int $categoryId;
    private int $profileId;

    protected function setUp(): void
    {
        parent::setUp();

        self::loadFixtures();

        // A real (non-vfs), uniquely-named directory -- see AccountHistoryRestoreTest for why: the
        // vfs root is shared process-wide, so runtime dirs are keyed to this test run alone.
        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-account-access-' . bin2hex(random_bytes(6))
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

        $this->context = $this->dic->get(Context::class);
        $this->context->initialize();
        // Every account below is created as the fixture admin: creating with a set of shared
        // users/groups only takes effect when the creator can change permissions
        // (AccountAcl::getShowPermission()), which the fixture admin always satisfies.
        $this->setContextUser(self::ADMIN_USER_ID, self::ADMIN_GROUP_ID, 'admin', isAdminApp: true, isAdminAcc: true);
        // AccountCryptService::getPasswordEncrypted() pulls the master password from here
        // (Service::getMasterKeyFromContext()); a real login sets this after checking the master
        // password against the stored hash. It is a session/config-level secret, not tied to
        // whichever user is "logged in", so it is set once and stays valid for every account
        // created or decrypted below regardless of which user the context is switched to.
        $this->context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);

        // Shared across every scenario: one client and one category (every account needs both) and
        // one user profile purely to satisfy User.userProfileId's foreign key (see the class
        // docblock for why its bits are never read).
        $suffix = bin2hex(random_bytes(4));
        $this->clientId = $this->dic->get(ClientService::class)->create(
            new Client(['name' => 'AA Client ' . $suffix, 'description' => 'AA client ' . $suffix])
        );
        $this->categoryId = $this->dic->get(CategoryService::class)->create(
            new Category(['name' => 'AA Category ' . $suffix, 'description' => 'AA category ' . $suffix])
        );
        $this->profileId = $this->dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'AA Profile ' . $suffix]))->dehydrate(new ProfileData())
        );
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The owner of an account can always read its password -- the baseline every other assertion in
     * this file is contrasted against.
     */
    public function testOwnerCanReadTheAccountAndItsPassword(): void
    {
        $ownerGroupId = $this->createGroup('owner');
        $ownerId = $this->createUser('owner', $ownerGroupId);

        $password = 'OwnerPass!' . bin2hex(random_bytes(4));
        $accountId = $this->createAccount('owner', $password, $ownerId, $ownerGroupId);

        $this->setContextUser($ownerId, $ownerGroupId, 'owner');

        self::assertSame(
            $password,
            $this->readPassword($accountId),
            'the owner could not read their own account\'s password'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $accountId),
            'the ACL denied the owner view access to their own account'
        );
        self::assertTrue(
            $this->searchContainsId($accountId),
            'the owner\'s own account did not show up in their own search results'
        );
    }

    /**
     * A user the account is shared with for viewing only can read the password, but the same ACL
     * refuses them edit access -- the view/edit distinction is per share, not all-or-nothing.
     */
    public function testUserSharedForViewingCanReadButNotEditThePassword(): void
    {
        $ownerGroupId = $this->createGroup('sv-owner');
        $ownerId = $this->createUser('sv-owner', $ownerGroupId);

        $viewerGroupId = $this->createGroup('sv-viewer');
        $viewerId = $this->createUser('sv-viewer', $viewerGroupId);

        $password = 'SharedViewPass!' . bin2hex(random_bytes(4));
        $accountId = $this->createAccount('sv', $password, $ownerId, $ownerGroupId, usersView: [$viewerId]);

        $this->setContextUser($viewerId, $viewerGroupId, 'sv-viewer');

        self::assertSame(
            $password,
            $this->readPassword($accountId),
            'a user shared with for viewing could not read the account password'
        );
        self::assertTrue(
            $this->searchContainsId($accountId),
            'a user shared with for viewing did not see the account in their search results'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $accountId),
            'the ACL denied view access to a user explicitly shared with for viewing'
        );
        self::assertFalse(
            $this->canAccess(AclActionsInterface::ACCOUNT_EDIT, $accountId),
            'the ACL granted edit access to a user who was only ever shared with for viewing'
        );
    }

    /**
     * A user who belongs to a group the account is shared with (view only) can read the password --
     * sharing with a group grants its members the same as sharing with them individually would.
     */
    public function testUserInASharedGroupCanReadThePassword(): void
    {
        $ownerGroupId = $this->createGroup('sg-owner');
        $ownerId = $this->createUser('sg-owner', $ownerGroupId);

        $sharedGroupId = $this->createGroup('sg-shared');
        $memberId = $this->createUser('sg-member', $sharedGroupId);

        $password = 'SharedGroupPass!' . bin2hex(random_bytes(4));
        $accountId = $this->createAccount('sg', $password, $ownerId, $ownerGroupId, groupsView: [$sharedGroupId]);

        $this->setContextUser($memberId, $sharedGroupId, 'sg-member');

        self::assertSame(
            $password,
            $this->readPassword($accountId),
            'a member of a group the account is shared with could not read the account password'
        );
        self::assertTrue(
            $this->searchContainsId($accountId),
            'a member of a group the account is shared with did not see it in their search results'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $accountId),
            'the ACL denied view access to a member of a group the account is shared with'
        );
    }

    /**
     * The property that matters most: a user the account was never shared with -- directly, nor
     * through a group, nor as owner -- can read neither the account nor its password, through
     * either path (search listing or fetch-by-id). The refusal is checked against the ACL itself
     * (AccountAclService::getAcl()), not re-derived, and the same user is then shown to read a
     * second, genuinely-shared account without any trouble, so the refusal above is provably this
     * account's ACL and not "this user can never read anything".
     */
    public function testUserNotSharedCannotReadTheAccountOrItsPassword(): void
    {
        $ownerGroupId = $this->createGroup('ns-owner');
        $ownerId = $this->createUser('ns-owner', $ownerGroupId);

        $strangerGroupId = $this->createGroup('ns-stranger');
        $strangerId = $this->createUser('ns-stranger', $strangerGroupId);

        // A third user the private account IS shared with, so it is a normal, actually-shared
        // account rather than one nobody but its owner can ever see -- the stranger is the one
        // deliberately left out of its sharing list.
        $viewerGroupId = $this->createGroup('ns-viewer');
        $viewerId = $this->createUser('ns-viewer', $viewerGroupId);

        $privatePassword = 'PrivatePass!' . bin2hex(random_bytes(4));
        $privateAccountId = $this->createAccount(
            'ns-private',
            $privatePassword,
            $ownerId,
            $ownerGroupId,
            usersView: [$viewerId]
        );

        // A second, independent account genuinely shared WITH the stranger -- the positive control.
        $sharedPassword = 'SharedWithStrangerPass!' . bin2hex(random_bytes(4));
        $sharedAccountId = $this->createAccount(
            'ns-shared',
            $sharedPassword,
            $ownerId,
            $ownerGroupId,
            usersView: [$strangerId]
        );

        // Verified while still logged in as admin, straight off the account's own sharing list
        // (not through anything that applies an ACL): the stranger genuinely is absent from it.
        $accountService = $this->dic->get(AccountService::class);
        $sharedUserIds = array_map(
            static fn($item) => $item->getId(),
            $accountService->withUsers(
                new AccountEnrichedDto($accountService->getByIdEnriched($privateAccountId))
            )->getUsers()
        );
        self::assertNotContains(
            $strangerId,
            $sharedUserIds,
            'setup: the stranger must not appear in the private account\'s own sharing list'
        );

        $this->setContextUser($strangerId, $strangerGroupId, 'ns-stranger');

        // The real ACL, asked directly, must refuse view access.
        self::assertFalse(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $privateAccountId),
            'the ACL granted view access to a user the account was never shared with'
        );

        // The listing path must not surface the account at all.
        self::assertFalse(
            $this->searchContainsId($privateAccountId),
            'a user not shared with saw the private account in their own search results'
        );

        // The by-id path must refuse with the same not-found refusal the ACL-scoped query produces
        // for zero matching rows -- not some unrelated failure.
        try {
            $this->readPassword($privateAccountId);
            self::fail('a user not shared with was able to read the account password by id');
        } catch (NoSuchItemException $e) {
            self::assertSame('Account not found', $e->getMessage());
        }

        // The positive control, on the very same (still logged in) user: an account genuinely
        // shared with them is readable both ways.
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $sharedAccountId),
            'setup: the stranger should have had view access to the account actually shared with them'
        );
        self::assertTrue(
            $this->searchContainsId($sharedAccountId),
            'the account actually shared with the stranger did not appear in their search results'
        );
        self::assertSame(
            $sharedPassword,
            $this->readPassword($sharedAccountId),
            'the stranger could not read the password of the account actually shared with them'
        );
    }

    /**
     * An application administrator reads any account, shared or not -- the deliberate bypass in
     * AccountAcl::compileAccountAccess() and in AccountFilterBuilder::isFilterWithoutGlobalSearch().
     */
    public function testApplicationAdministratorCanReadAnyAccount(): void
    {
        $ownerGroupId = $this->createGroup('appadm-owner');
        $ownerId = $this->createUser('appadm-owner', $ownerGroupId);

        $adminGroupId = $this->createGroup('appadm-admin');
        $appAdminId = $this->createUser('appadm-admin', $adminGroupId, isAdminApp: true);

        $password = 'AppAdminPass!' . bin2hex(random_bytes(4));
        // Not shared with the admin in any way -- ownership and every share belong to someone else.
        $accountId = $this->createAccount('appadm', $password, $ownerId, $ownerGroupId);

        $this->setContextUser($appAdminId, $adminGroupId, 'appadm-admin', isAdminApp: true);

        self::assertSame(
            $password,
            $this->readPassword($accountId),
            'an application administrator could not read an unrelated account\'s password'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $accountId),
            'the ACL denied an application administrator view access to an unrelated account'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_EDIT, $accountId),
            'the ACL denied an application administrator edit access to an unrelated account'
        );
        self::assertTrue(
            $this->searchContainsId($accountId),
            'an application administrator did not see an unrelated account in their search results'
        );
    }

    /**
     * An accounts administrator reads any account too -- the same bypass, granted through isAdminAcc
     * rather than isAdminApp.
     */
    public function testAccountsAdministratorCanReadAnyAccount(): void
    {
        $ownerGroupId = $this->createGroup('accadm-owner');
        $ownerId = $this->createUser('accadm-owner', $ownerGroupId);

        $adminGroupId = $this->createGroup('accadm-admin');
        $accAdminId = $this->createUser('accadm-admin', $adminGroupId, isAdminAcc: true);

        $password = 'AccAdminPass!' . bin2hex(random_bytes(4));
        $accountId = $this->createAccount('accadm', $password, $ownerId, $ownerGroupId);

        $this->setContextUser($accAdminId, $adminGroupId, 'accadm-admin', isAdminAcc: true);

        self::assertSame(
            $password,
            $this->readPassword($accountId),
            'an accounts administrator could not read an unrelated account\'s password'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_VIEW, $accountId),
            'the ACL denied an accounts administrator view access to an unrelated account'
        );
        self::assertTrue(
            $this->canAccess(AclActionsInterface::ACCOUNT_EDIT, $accountId),
            'the ACL denied an accounts administrator edit access to an unrelated account'
        );
        self::assertTrue(
            $this->searchContainsId($accountId),
            'an accounts administrator did not see an unrelated account in their search results'
        );
    }

    /**
     * Switches which user Context reports as logged in, the same technique
     * AccountHistoryRestoreTest/BruteForceTrackingTest use. User profile is deliberately never set
     * here -- see the class docblock.
     */
    private function setContextUser(
        int $id,
        int $groupId,
        string $login,
        bool $isAdminApp = false,
        bool $isAdminAcc = false
    ): void {
        $this->context->setUserData(
            new UserDto(
                id: $id,
                userGroupId: $groupId,
                login: $login,
                isAdminApp: $isAdminApp,
                isAdminAcc: $isAdminAcc,
            )
        );
    }

    private function createGroup(string $suffix): int
    {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(UserGroupService::class)->create(
            new UserGroupModel(['name' => 'AA Group ' . $unique, 'description' => 'AA group ' . $unique])
        );
    }

    private function createUser(string $suffix, int $groupId, bool $isAdminApp = false, bool $isAdminAcc = false): int
    {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'AA User ' . $unique,
                    'login' => 'aa-' . $unique,
                    'userGroupId' => $groupId,
                    'userProfileId' => $this->profileId,
                    'isAdminApp' => $isAdminApp,
                    'isAdminAcc' => $isAdminAcc,
                    'isDisabled' => false,
                    'isChangePass' => false,
                    'isLdap' => false,
                ]
            ),
            'AaUserPass!' . bin2hex(random_bytes(4)),
            self::MASTER_PASS
        );
    }

    /**
     * Creates an account through the real service, exactly as an admin creating it on someone
     * else's behalf and sharing it would (a create call from anyone who is not the fixture admin
     * used throughout setUp() would silently drop the sharing lists -- see
     * AccountAcl::getShowPermission()).
     *
     * @param int[] $usersView
     * @param int[] $usersEdit
     * @param int[] $groupsView
     * @param int[] $groupsEdit
     */
    private function createAccount(
        string $suffix,
        string $password,
        int $ownerId,
        int $ownerGroupId,
        array $usersView = [],
        array $usersEdit = [],
        array $groupsView = [],
        array $groupsEdit = []
    ): int {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(AccountService::class)->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $ownerId,
                userGroupId: $ownerGroupId,
                name: 'AA Account ' . $unique,
                login: 'aa-login-' . $unique,
                pass: $password,
                url: 'https://example.test/aa/' . $unique,
                notes: 'AA notes for ' . $unique,
                usersView: $usersView,
                usersEdit: $usersEdit,
                userGroupsView: $groupsView,
                userGroupsEdit: $groupsEdit,
            )
        );
    }

    /**
     * @throws NoSuchItemException when the account is out of the current context user's reach --
     *     see AccountFilterBuilder::buildFilter().
     */
    private function readPassword(int $accountId): string
    {
        $item = $this->dic->get(AccountService::class)->getPasswordForId($accountId);

        return $this->dic->get(CryptInterface::class)->decrypt(
            $item->getPass() ?? '',
            $item->getKey() ?? '',
            self::MASTER_PASS
        );
    }

    /**
     * Reproduces the object-level gate AccountHelper::checkAccess() / AccountAclEnforcer's
     * checkAccountAccess() use in production: fetch the account's data (unscoped -- the whole point
     * of AccountAclService::getAcl() is to gate access to it afterward), then ask the real ACL
     * whether the current context user may perform $actionId on it.
     */
    private function canAccess(int $actionId, int $accountId): bool
    {
        $accountService = $this->dic->get(AccountService::class);

        $accountEnrichedDto = $accountService->withUserGroups(
            $accountService->withUsers(
                new AccountEnrichedDto($accountService->getByIdEnriched($accountId))
            )
        );

        $permission = $this->dic->get(AccountAclService::class)->getAcl(
            $actionId,
            AccountAclDto::makeFromAccount($accountEnrichedDto)
        );

        return $permission->checkAccountAccess($actionId);
    }

    /**
     * Whether $accountId shows up for the current context user in an otherwise-unfiltered search --
     * the listing path, scoped the same way SearchController's is (AccountSearch ->
     * AccountFilterBuilder::buildFilter()).
     */
    private function searchContainsId(int $accountId): bool
    {
        $result = $this->dic->get(AccountSearchService::class)->getByFilter(AccountSearchFilterDto::build(null));

        foreach ($result->getDataAsArray() as $row) {
            if ((int)$row->getId() === $accountId) {
                return true;
            }
        }

        return false;
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
