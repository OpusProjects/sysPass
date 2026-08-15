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
use SP\Application\Account\Ports\AccountPresetService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Account\Dtos\AccountCreateDto;
use SP\Domain\Account\Dtos\AccountEnrichedDto;
use SP\Domain\Account\Dtos\AccountUpdateDto;
use SP\Domain\Category\Models\Category;
use SP\Domain\Client\Models\Client;
use SP\Domain\Core\Bootstrap\Path;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Database\Ports\DbStorageHandler;
use SP\Domain\File\FileSystem;
use SP\Domain\ItemPreset\Models\AccountPermission as AccountPermissionPreset;
use SP\Domain\ItemPreset\Models\AccountPrivate;
use SP\Domain\ItemPreset\Models\ItemPreset as ItemPresetModel;
use SP\Domain\ItemPreset\Models\Password as PasswordPreset;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
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
 * SP\Application\Account\Services\AccountPreset decides how an administrator's item presets are
 * applied when an account is created or edited. Both of its entry points --
 * checkPasswordPreset() and addPresetPermissions() -- only ever consult
 * SP\Application\ItemPreset\Services\ItemPreset::getForCurrentUser(), which in turn is answered by
 * SP\Infrastructure\Adapter\Out\ItemPreset\Repositories\ItemPreset::getByFilter(): a single row,
 * the highest of "priority + 3" (direct user match) / "priority + 2" (group match, including
 * secondary UserToUserGroup membership) / "priority + 1" (profile match), LIMIT 1. Only a real
 * database exercises that SQL tie-break; IntegrationTestCase mocks the database away entirely, so
 * nothing built on it can tell a real preset selection from an untested one.
 *
 * This drives the real AccountPresetService and the real AccountService against a real database,
 * built by hand exactly like AccountAccessTest (real DomainDefinitions + CoreDefinitions('cli') +
 * the Cli module.php, DbStorageHandler swapped for a real connection), switching which user is
 * "logged in" via Context::setUserData() between assertions.
 *
 * What each test establishes, against the real code:
 *  - testDirectUserPermissionPresetOutranksGroupPreset /
 *    testGroupPermissionPresetOutranksProfilePreset: the priority order the repository's
 *    IF/IF/IF score expression encodes -- direct user match beats group match beats profile
 *    match -- is the one that actually governs which single preset a user with more than one
 *    match ends up with.
 *  - testNonFixedPermissionPresetAppliesNothing / testNonFixedPrivateAccountPresetAppliesNothing:
 *    a preset that is not "fixed" is never applied by either entry point, for either preset
 *    type -- consistent with AccountHelper::setViewForBlank(), which reads the very same
 *    getForCurrentUser() result to pre-fill the create form regardless of "fixed" and relies on
 *    the server-side re-application (setPresetPrivate() / addPresetPermissions()) to enforce it
 *    only when "fixed" is set. A non-fixed preset is a form default, not a rule.
 *  - testFixedPermissionPresetNamingOnlyTheOwnerAppliesNothing: addPresetPermissions() excludes
 *    the current user/group from the preset's own lists (array_diff), so a preset naming only the
 *    creating user's id ends up with nothing to grant -- no exception, no rows, just silence.
 *  - testFixedPrivateAccountPresetForcesPrivacyOnCreateDespiteRequestSayingOtherwise /
 *    ...OnUpdateDespiteRequestClearingIt: ITEM_TYPE_ACCOUNT_PRIVATE is never read inside
 *    AccountPreset.php at all -- it is applied in
 *    SP\Application\Account\Services\Account::setPresetPrivate(), called from both create() and
 *    update(), which overrides whatever isPrivate/isPrivateGroup the caller asked for once the
 *    preset is fixed.
 *  - testCheckPasswordPresetRejectsNonCompliantPasswordOnCreateAndAcceptsCompliantOne /
 *    ...OnEditPass: checkPasswordPreset() itself enforces a fixed password preset identically for
 *    an AccountCreateDto and an AccountUpdateDto -- it is the callers (AccountForm's
 *    ACCOUNT_CREATE/ACCOUNT_COPY/ACCOUNT_EDIT_PASS branches, the API's CreateController and
 *    EditPassController) that invoke it on both the create path and the change-password path.
 *  - testAccountServiceNeverEnforcesThePasswordPresetOnItsOwn: unlike addPresetPermissions(),
 *    which SP\Application\Account\Services\Account::create()/update() call unconditionally
 *    themselves, checkPasswordPreset() is never called from inside AccountService at all --
 *    calling create() or editPassword() directly, the way
 *    SP\Application\Import\Services\ImportBase::addAccount() does, stores a password that
 *    violates a fixed preset without ever consulting it.
 */
#[Group('integration')]
final class AccountPresetApplicationTest extends TestCase
{
    use DatabaseTrait;

    /**
     * The plaintext behind the bcrypt hash `syspass.sql` stores in Config.masterPwd. Same constant
     * AccountAccessTest/AccountHistoryRestoreTest/ImportFormatsTest/ApiTestCase use.
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

        $this->root = FileSystem::buildPath(
            sys_get_temp_dir(),
            'syspass-account-preset-' . bin2hex(random_bytes(6))
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
        $this->setContextUser(self::ADMIN_USER_ID, self::ADMIN_GROUP_ID, 'admin', isAdminApp: true, isAdminAcc: true);
        $this->context->setTrasientKey(Context::MASTER_PASSWORD_KEY, self::MASTER_PASS);

        $suffix = bin2hex(random_bytes(4));
        $this->clientId = $this->dic->get(ClientService::class)->create(
            new Client(['name' => 'AP Client ' . $suffix, 'description' => 'AP client ' . $suffix])
        );
        $this->categoryId = $this->dic->get(CategoryService::class)->create(
            new Category(['name' => 'AP Category ' . $suffix, 'description' => 'AP category ' . $suffix])
        );
        $this->profileId = $this->createProfile('shared');
    }

    protected function tearDown(): void
    {
        FileSystem::rmdirRecursive($this->root);

        parent::tearDown();
    }

    /**
     * A user who matches a preset both directly (by id) and through their group ends up with the
     * DIRECT preset, never the group one -- the repository's score expression gives a userId
     * match "priority + 3" against a userGroupId match's "priority + 2", and both presets here
     * carry the same priority (0), so only the match kind decides it.
     */
    public function testDirectUserPermissionPresetOutranksGroupPreset(): void
    {
        $groupId = $this->createGroup('du');
        $userId = $this->createUser('du', $groupId);

        $groupTargetId = $this->createUser('du-group-target', $this->createGroup('du-group-target'));
        $userTargetId = $this->createUser('du-user-target', $this->createGroup('du-user-target'));

        $this->createPermissionPreset(
            userGroupId: $groupId,
            fixed: 1,
            priority: 0,
            data: new AccountPermissionPreset([$groupTargetId], [], [], [])
        );
        $this->createPermissionPreset(
            userId: $userId,
            fixed: 1,
            priority: 0,
            data: new AccountPermissionPreset([$userTargetId], [], [], [])
        );

        $this->setContextUser($userId, $groupId, 'du-user');
        $accountId = $this->createAccount('du', 'DirectUserPass!' . bin2hex(random_bytes(4)), $userId, $groupId);

        $viewIds = $this->viewUserIds($accountId);

        self::assertContains(
            $userTargetId,
            $viewIds,
            'the user-targeted preset (the more specific match) was not applied'
        );
        self::assertNotContains(
            $groupTargetId,
            $viewIds,
            'the group-targeted preset was applied even though a direct user match outranks it'
        );
    }

    /**
     * Symmetrically, a user who matches a preset both through their group and through their
     * profile ends up with the GROUP preset, never the profile one -- "priority + 2" against
     * "priority + 1".
     */
    public function testGroupPermissionPresetOutranksProfilePreset(): void
    {
        $profileId = $this->createProfile('gp');
        $groupId = $this->createGroup('gp');
        $userId = $this->createUser('gp', $groupId, $profileId);

        $profileTargetId = $this->createUser('gp-profile-target', $this->createGroup('gp-profile-target'));
        $groupTargetId = $this->createUser('gp-group-target', $this->createGroup('gp-group-target'));

        $this->createPermissionPreset(
            userProfileId: $profileId,
            fixed: 1,
            priority: 0,
            data: new AccountPermissionPreset([$profileTargetId], [], [], [])
        );
        $this->createPermissionPreset(
            userGroupId: $groupId,
            fixed: 1,
            priority: 0,
            data: new AccountPermissionPreset([$groupTargetId], [], [], [])
        );

        $this->setContextUser($userId, $groupId, 'gp-user', userProfileId: $profileId);
        $accountId = $this->createAccount('gp', 'GroupProfilePass!' . bin2hex(random_bytes(4)), $userId, $groupId);

        $viewIds = $this->viewUserIds($accountId);

        self::assertContains(
            $groupTargetId,
            $viewIds,
            'the group-targeted preset (the more specific match) was not applied'
        );
        self::assertNotContains(
            $profileTargetId,
            $viewIds,
            'the profile-targeted preset was applied even though a group match outranks it'
        );
    }

    /**
     * A preset that is not "fixed" is a suggestion, not a rule: addPresetPermissions() only acts
     * when getFixed() === truthy, so a matching preset with fixed=0 must add nothing at all.
     */
    public function testNonFixedPermissionPresetAppliesNothing(): void
    {
        $groupId = $this->createGroup('nf');
        $userId = $this->createUser('nf', $groupId);
        $targetId = $this->createUser('nf-target', $this->createGroup('nf-target'));

        $this->createPermissionPreset(
            userId: $userId,
            fixed: 0,
            priority: 0,
            data: new AccountPermissionPreset([$targetId], [], [], [])
        );

        $this->setContextUser($userId, $groupId, 'nf-user');
        $accountId = $this->createAccount('nf', 'NonFixedPass!' . bin2hex(random_bytes(4)), $userId, $groupId);

        self::assertSame(
            [],
            $this->viewUserIds($accountId),
            'a non-fixed permission preset added sharing rows even though it is not fixed'
        );
    }

    /**
     * Same as above for the private-account preset type: a non-fixed AccountPrivate preset must
     * not force the account private, even though the field the account was created with says
     * otherwise (isPrivate: false), because a non-fixed preset never gates
     * Account::setPresetPrivate() either.
     */
    public function testNonFixedPrivateAccountPresetAppliesNothing(): void
    {
        $groupId = $this->createGroup('nfp');
        $userId = $this->createUser('nfp', $groupId);

        $this->createPrivatePreset(
            userId: $userId,
            fixed: 0,
            data: new AccountPrivate(true, false)
        );

        $this->setContextUser($userId, $groupId, 'nfp-user');
        $accountId = $this->dic->get(AccountService::class)->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $userId,
                userGroupId: $groupId,
                name: 'AP Account nfp-' . bin2hex(random_bytes(3)),
                login: 'ap-login-nfp-' . bin2hex(random_bytes(3)),
                pass: 'NonFixedPrivatePass!' . bin2hex(random_bytes(4)),
                isPrivate: false,
            )
        );

        self::assertSame(
            0,
            $this->dic->get(AccountService::class)->getById($accountId)->getIsPrivate(),
            'a non-fixed private-account preset forced the account private'
        );
    }

    /**
     * addPresetPermissions() excludes the current user/group from a preset's own lists
     * (array_diff against the logged-in user's id/userGroupId). A preset that names ONLY the
     * creating user must therefore add nothing -- not fail, not throw, just apply nothing, which
     * is exactly what an administrator who thinks they wrote a rule would NOT expect to see.
     */
    public function testFixedPermissionPresetNamingOnlyTheOwnerAppliesNothing(): void
    {
        $groupId = $this->createGroup('oo');
        $userId = $this->createUser('oo', $groupId);

        $this->createPermissionPreset(
            userId: $userId,
            fixed: 1,
            priority: 0,
            data: new AccountPermissionPreset([$userId], [$userId], [], [])
        );

        $this->setContextUser($userId, $groupId, 'oo-user');
        $accountId = $this->createAccount('oo', 'OwnerOnlyPass!' . bin2hex(random_bytes(4)), $userId, $groupId);

        self::assertSame(
            [],
            $this->viewUserIds($accountId),
            'a preset naming only the creating user still added a sharing row for them'
        );
        self::assertSame(
            [],
            $this->editUserIds($accountId),
            'a preset naming only the creating user still added an edit-sharing row for them'
        );
    }

    /**
     * ITEM_TYPE_ACCOUNT_PRIVATE is never consulted by AccountPreset.php -- it is applied in
     * SP\Application\Account\Services\Account::setPresetPrivate(), called from create(). A fixed
     * preset forces the account private even though the create request explicitly asked for a
     * PUBLIC (non-private) account.
     */
    public function testFixedPrivateAccountPresetForcesPrivacyOnCreateDespiteRequestSayingOtherwise(): void
    {
        $groupId = $this->createGroup('priv-c');
        $userId = $this->createUser('priv-c', $groupId);

        $this->createPrivatePreset(
            userId: $userId,
            fixed: 1,
            data: new AccountPrivate(true, false)
        );

        $this->setContextUser($userId, $groupId, 'priv-c-user');
        $accountId = $this->dic->get(AccountService::class)->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $userId,
                userGroupId: $groupId,
                name: 'AP Account priv-c-' . bin2hex(random_bytes(3)),
                login: 'ap-login-priv-c-' . bin2hex(random_bytes(3)),
                pass: 'PrivateCreatePass!' . bin2hex(random_bytes(4)),
                isPrivate: false,
            )
        );

        self::assertSame(
            1,
            $this->dic->get(AccountService::class)->getById($accountId)->getIsPrivate(),
            'a fixed private-account preset did not force the account private on create'
        );
    }

    /**
     * Same fixed preset, exercised through update(): an account created public is then saved
     * again with isPrivate explicitly cleared to false, and the preset forces it back to private
     * regardless -- setPresetPrivate() re-applies on every update(), not just at creation time.
     */
    public function testFixedPrivateAccountPresetForcesPrivacyOnUpdateDespiteRequestClearingIt(): void
    {
        $groupId = $this->createGroup('priv-u');
        $userId = $this->createUser('priv-u', $groupId);

        $accountService = $this->dic->get(AccountService::class);

        $this->setContextUser($userId, $groupId, 'priv-u-user');
        $accountId = $accountService->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $userId,
                userGroupId: $groupId,
                name: 'AP Account priv-u-' . bin2hex(random_bytes(3)),
                login: 'ap-login-priv-u-' . bin2hex(random_bytes(3)),
                pass: 'PrivateUpdateInitial!' . bin2hex(random_bytes(4)),
                isPrivate: false,
            )
        );

        self::assertSame(
            0,
            $accountService->getById($accountId)->getIsPrivate(),
            'setup: the account must start out non-private'
        );

        // The preset is only created now, so the setup create() above was unaffected by it.
        $this->createPrivatePreset(
            userId: $userId,
            fixed: 1,
            data: new AccountPrivate(true, false)
        );

        $accountService->update(
            $accountId,
            new AccountUpdateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                name: 'AP Account priv-u-updated',
                login: 'ap-login-priv-u-updated',
                isPrivate: false,
            )
        );

        self::assertSame(
            1,
            $accountService->getById($accountId)->getIsPrivate(),
            'a fixed private-account preset did not force the account private on update'
        );
    }

    /**
     * checkPasswordPreset() enforces a fixed password preset on creation: a password shorter than
     * the preset's required length is refused with a ValidationException, and a compliant one
     * passes through untouched all the way to a stored, readable account.
     */
    public function testCheckPasswordPresetRejectsNonCompliantPasswordOnCreateAndAcceptsCompliantOne(): void
    {
        $groupId = $this->createGroup('pwc');
        $userId = $this->createUser('pwc', $groupId);

        $this->createPasswordPreset($userId, 12);

        $this->setContextUser($userId, $groupId, 'pwc-user');
        $accountPresetService = $this->dic->get(AccountPresetService::class);

        $weakDto = new AccountCreateDto(
            clientId: $this->clientId,
            categoryId: $this->categoryId,
            userId: $userId,
            userGroupId: $groupId,
            name: 'AP Account pwc',
            login: 'ap-login-pwc',
            pass: 'short1',
        );

        try {
            $accountPresetService->checkPasswordPreset($weakDto);
            self::fail('a password shorter than the fixed preset\'s required length was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('characters long', $e->getMessage());
        }

        $compliantPass = bin2hex(random_bytes(6));
        $compliantDto = $weakDto->mutate(['pass' => $compliantPass]);

        $checked = $accountPresetService->checkPasswordPreset($compliantDto);
        self::assertSame($compliantPass, $checked->pass);

        $accountId = $this->dic->get(AccountService::class)->create($checked);

        self::assertSame($compliantPass, $this->readPassword($accountId));
    }

    /**
     * The same fixed password preset, enforced identically for the change-password path
     * (AccountUpdateDto), the way SaveEditPassController / EditPassController / the API's
     * EditPassController call it before Account::editPassword() ever runs.
     */
    public function testCheckPasswordPresetRejectsNonCompliantPasswordOnEditPass(): void
    {
        $groupId = $this->createGroup('pwe');
        $userId = $this->createUser('pwe', $groupId);

        $this->createPasswordPreset($userId, 12);

        $this->setContextUser($userId, $groupId, 'pwe-user');
        $accountPresetService = $this->dic->get(AccountPresetService::class);

        $weakUpdateDto = new AccountUpdateDto(pass: 'short2');

        try {
            $accountPresetService->checkPasswordPreset($weakUpdateDto);
            self::fail('a password shorter than the fixed preset\'s required length was accepted on edit-pass');
        } catch (ValidationException $e) {
            self::assertStringContainsString('characters long', $e->getMessage());
        }
    }

    /**
     * Unlike addPresetPermissions(), which Account::create()/update() call themselves regardless
     * of who the caller is, checkPasswordPreset() is never invoked from inside AccountService.
     * Calling create() (or editPassword()) directly with a password that violates a fixed preset
     * -- exactly what ImportBase::addAccount() does -- stores it unchanged; nothing here refuses
     * it or even notices the preset exists. This documents the asymmetry between the two
     * AccountPreset.php entry points rather than endorsing it.
     */
    public function testAccountServiceNeverEnforcesThePasswordPresetOnItsOwn(): void
    {
        $groupId = $this->createGroup('pwb');
        $userId = $this->createUser('pwb', $groupId);

        $this->createPasswordPreset($userId, 12);

        $this->setContextUser($userId, $groupId, 'pwb-user');
        $accountService = $this->dic->get(AccountService::class);

        $weakPass = 'short3';
        $accountId = $accountService->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $userId,
                userGroupId: $groupId,
                name: 'AP Account pwb',
                login: 'ap-login-pwb',
                pass: $weakPass,
            )
        );

        self::assertSame(
            $weakPass,
            $this->readPassword($accountId),
            'AccountService::create() stored a different password than requested'
        );

        $anotherWeakPass = 'short4';
        $accountService->editPassword(
            $accountId,
            new AccountUpdateDto(pass: $anotherWeakPass)
        );

        self::assertSame(
            $anotherWeakPass,
            $this->readPassword($accountId),
            'AccountService::editPassword() stored a different password than requested'
        );
    }

    private function setContextUser(
        int $id,
        int $groupId,
        string $login,
        bool $isAdminApp = false,
        bool $isAdminAcc = false,
        ?int $userProfileId = null
    ): void {
        $this->context->setUserData(
            new UserDto(
                id: $id,
                userGroupId: $groupId,
                userProfileId: $userProfileId,
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
            new UserGroupModel(['name' => 'AP Group ' . $unique, 'description' => 'AP group ' . $unique])
        );
    }

    private function createProfile(string $suffix): int
    {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(UserProfileService::class)->create(
            (new UserProfileModel(['name' => 'AP Profile ' . $unique]))->dehydrate(new ProfileData())
        );
    }

    private function createUser(string $suffix, int $groupId, ?int $profileId = null): int
    {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(UserService::class)->createWithMasterPass(
            new UserModel(
                [
                    'name' => 'AP User ' . $unique,
                    'login' => 'ap-' . $unique,
                    'userGroupId' => $groupId,
                    'userProfileId' => $profileId ?? $this->profileId,
                    'isAdminApp' => false,
                    'isAdminAcc' => false,
                    'isDisabled' => false,
                    'isChangePass' => false,
                    'isLdap' => false,
                ]
            ),
            'ApUserPass!' . bin2hex(random_bytes(4)),
            self::MASTER_PASS
        );
    }

    private function createAccount(string $suffix, string $password, int $ownerId, int $ownerGroupId): int
    {
        $unique = $suffix . '-' . bin2hex(random_bytes(3));

        return $this->dic->get(AccountService::class)->create(
            new AccountCreateDto(
                clientId: $this->clientId,
                categoryId: $this->categoryId,
                userId: $ownerId,
                userGroupId: $ownerGroupId,
                name: 'AP Account ' . $unique,
                login: 'ap-login-' . $unique,
                pass: $password,
                url: 'https://example.test/ap/' . $unique,
                notes: 'AP notes for ' . $unique,
            )
        );
    }

    private function createPermissionPreset(
        ?int $userId = null,
        ?int $userGroupId = null,
        ?int $userProfileId = null,
        int $fixed = 1,
        int $priority = 0,
        AccountPermissionPreset $data = new AccountPermissionPreset([], [], [], [])
    ): int {
        return $this->dic->get(ItemPresetService::class)->create(
            (new ItemPresetModel([
                                      'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION,
                                      'userId' => $userId,
                                      'userGroupId' => $userGroupId,
                                      'userProfileId' => $userProfileId,
                                      'fixed' => $fixed,
                                      'priority' => $priority,
                                  ]))->dehydrate($data)
        );
    }

    private function createPrivatePreset(
        ?int $userId = null,
        ?int $userGroupId = null,
        ?int $userProfileId = null,
        int $fixed = 1,
        int $priority = 0,
        AccountPrivate $data = new AccountPrivate(false, false)
    ): int {
        return $this->dic->get(ItemPresetService::class)->create(
            (new ItemPresetModel([
                                      'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE,
                                      'userId' => $userId,
                                      'userGroupId' => $userGroupId,
                                      'userProfileId' => $userProfileId,
                                      'fixed' => $fixed,
                                      'priority' => $priority,
                                  ]))->dehydrate($data)
        );
    }

    private function createPasswordPreset(int $userId, int $length): int
    {
        $data = new PasswordPreset(
            $length,
            false,
            false,
            false,
            false,
            false,
            false,
            0,
            0,
            null
        );

        return $this->dic->get(ItemPresetService::class)->create(
            (new ItemPresetModel([
                                      'type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD,
                                      'userId' => $userId,
                                      'userGroupId' => null,
                                      'userProfileId' => null,
                                      'fixed' => 1,
                                      'priority' => 0,
                                  ]))->dehydrate($data)
        );
    }

    /**
     * @return int[]
     */
    private function viewUserIds(int $accountId): array
    {
        $accountService = $this->dic->get(AccountService::class);

        $users = $accountService->withUsers(
            new AccountEnrichedDto($accountService->getByIdEnriched($accountId))
        )->getUsers();

        return array_map(static fn($item) => (int)$item->getId(), $users);
    }

    /**
     * @return int[]
     */
    private function editUserIds(int $accountId): array
    {
        $accountService = $this->dic->get(AccountService::class);

        $users = $accountService->withUsers(
            new AccountEnrichedDto($accountService->getByIdEnriched($accountId))
        )->getUsers();

        return array_map(
            static fn($item) => (int)$item->getId(),
            array_filter($users, static fn($item) => (int)$item->getIsEdit() === 1)
        );
    }

    private function readPassword(int $accountId): string
    {
        $accountService = $this->dic->get(AccountService::class);
        $item = $accountService->getPasswordForId($accountId);

        return $this->dic->get(CryptInterface::class)->decrypt(
            $item->getPass() ?? '',
            $item->getKey() ?? '',
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
