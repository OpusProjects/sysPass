<?php
/**
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

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Acl\ActionsInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Acl\Acl;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AclTest
 *
 * Acl::checkUserAccess() is the single place deciding whether a user may perform an action, and
 * it had no test of its own. It cannot be covered from the integration suite either: that
 * harness replaces AclInterface with a stub whose checkUserAccess() always returns true, so
 * every controller guard passes there regardless of the profile.
 */
#[Group('unitary')]
class AclTest extends UnitaryTestCase
{
    /**
     * An action is granted exactly when its profile flag is set, and refused when it is not.
     *
     * @throws Exception
     */
    #[DataProvider('actionToProfileFlagProvider')]
    public function testActionIsGrantedOnlyByItsOwnFlag(int $actionId, string $flag): void
    {
        self::assertTrue($this->acl([$flag => true])->checkUserAccess($actionId));
        self::assertFalse($this->acl([])->checkUserAccess($actionId));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function actionToProfileFlagProvider(): array
    {
        return [
            'view an account' => [AclActionsInterface::ACCOUNT_VIEW, 'accView'],
            'view a password' => [AclActionsInterface::ACCOUNT_VIEW_PASS, 'accViewPass'],
            'edit an account' => [AclActionsInterface::ACCOUNT_EDIT, 'accEdit'],
            'edit a password' => [AclActionsInterface::ACCOUNT_EDIT_PASS, 'accEditPass'],
            'create an account' => [AclActionsInterface::ACCOUNT_CREATE, 'accAdd'],
            'delete an account' => [AclActionsInterface::ACCOUNT_DELETE, 'accDelete'],
            'account files' => [AclActionsInterface::ACCOUNT_FILE, 'accFiles'],
            'account history' => [AclActionsInterface::ACCOUNT_HISTORY_VIEW, 'accViewHistory'],
            'manage users' => [AclActionsInterface::USER, 'mgmUsers'],
            'manage groups' => [AclActionsInterface::GROUP, 'mgmGroups'],
            'manage profiles' => [AclActionsInterface::PROFILE, 'mgmProfiles'],
            'manage categories' => [AclActionsInterface::CATEGORY, 'mgmCategories'],
            'manage tags' => [AclActionsInterface::TAG, 'mgmTags'],
            'manage api tokens' => [AclActionsInterface::AUTHTOKEN, 'mgmApiTokens'],
            'manage public links' => [AclActionsInterface::PUBLICLINK, 'mgmPublicLinks'],
            'read the event log' => [AclActionsInterface::EVENTLOG, 'evl'],
            'encryption config' => [AclActionsInterface::CONFIG_CRYPT, 'configEncryption'],
            'backup config' => [AclActionsInterface::CONFIG_BACKUP, 'configBackup'],
        ];
    }

    /**
     * The brute-force lockouts are administered under user management, so a profile without it
     * may neither read nor lift them.
     *
     * @throws Exception
     */
    #[DataProvider('trackActionProvider')]
    public function testTrackActionsRequireUserManagement(int $actionId): void
    {
        self::assertTrue($this->acl(['mgmUsers' => true])->checkUserAccess($actionId));
        self::assertFalse($this->acl([])->checkUserAccess($actionId));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function trackActionProvider(): array
    {
        return [
            'list lockouts' => [AclActionsInterface::TRACK_SEARCH],
            'lift a lockout' => [AclActionsInterface::TRACK_UNLOCK],
            'clear the lockouts' => [AclActionsInterface::TRACK_CLEAR],
        ];
    }

    /**
     * Copying an account exposes its contents into a new one, so it takes both the right to read
     * an account and the right to create one — either alone is not enough.
     *
     * @throws Exception
     */
    public function testCopyingAnAccountNeedsBothCreateAndView(): void
    {
        self::assertFalse($this->acl(['accAdd' => true])->checkUserAccess(AclActionsInterface::ACCOUNT_COPY));
        self::assertFalse($this->acl(['accView' => true])->checkUserAccess(AclActionsInterface::ACCOUNT_COPY));
        self::assertTrue(
            $this->acl(['accAdd' => true, 'accView' => true])->checkUserAccess(AclActionsInterface::ACCOUNT_COPY)
        );
    }

    /**
     * Editing an account is enough to view it, so the view grant is not required separately.
     *
     * @throws Exception
     */
    public function testEditingAnAccountImpliesViewingIt(): void
    {
        self::assertTrue($this->acl(['accEdit' => true])->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW));
    }

    /**
     * The management landing pages open for any one of the things they collect, so a profile
     * holding a single grant still reaches them.
     *
     * @throws Exception
     */
    public function testManagementPagesOpenForAnySingleGrant(): void
    {
        self::assertTrue($this->acl(['mgmTags' => true])->checkUserAccess(AclActionsInterface::ITEMS_MANAGE));
        self::assertTrue($this->acl(['configBackup' => true])->checkUserAccess(AclActionsInterface::CONFIG));
        self::assertTrue($this->acl(['evl' => true])->checkUserAccess(AclActionsInterface::SECURITY_MANAGE));
        self::assertTrue($this->acl(['mgmGroups' => true])->checkUserAccess(AclActionsInterface::ACCESS_MANAGE));

        self::assertFalse($this->acl([])->checkUserAccess(AclActionsInterface::ITEMS_MANAGE));
        self::assertFalse($this->acl([])->checkUserAccess(AclActionsInterface::CONFIG));
    }

    /**
     * A user may always change their own password; changing somebody else's takes user
     * management.
     *
     * @throws Exception
     */
    public function testChangingAPasswordIsAllowedForOneselfOrByAnAdministrator(): void
    {
        $acl = $this->acl([], ['id' => 500]);

        self::assertTrue($acl->checkUserAccess(AclActionsInterface::USER_EDIT_PASS, 500));
        self::assertFalse($acl->checkUserAccess(AclActionsInterface::USER_EDIT_PASS, 501));

        self::assertTrue(
            $this->acl(['mgmUsers' => true], ['id' => 500])
                 ->checkUserAccess(AclActionsInterface::USER_EDIT_PASS, 501)
        );
    }

    /**
     * An application administrator bypasses the profile entirely — including for an action that
     * is not in the table at all.
     *
     * @throws Exception
     */
    public function testAnApplicationAdministratorIsAllowedEverything(): void
    {
        $acl = $this->acl([], ['isAdminApp' => true]);

        self::assertTrue($acl->checkUserAccess(AclActionsInterface::CONFIG_CRYPT));
        self::assertTrue($acl->checkUserAccess(AclActionsInterface::USER_DELETE));
        self::assertTrue($acl->checkUserAccess(-1));
    }

    /**
     * An account administrator bypasses the profile for the account actions, but is not thereby
     * granted the management ones.
     *
     * @throws Exception
     */
    public function testAnAccountAdministratorIsAllowedTheAccountActionsOnly(): void
    {
        $acl = $this->acl([], ['isAdminAcc' => true]);

        self::assertTrue($acl->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW));
        self::assertTrue($acl->checkUserAccess(AclActionsInterface::ACCOUNT_DELETE));
        self::assertTrue($acl->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW_PASS));

        self::assertFalse($acl->checkUserAccess(AclActionsInterface::USER));
        self::assertFalse($acl->checkUserAccess(AclActionsInterface::CONFIG_CRYPT));
    }

    /**
     * Requesting access to an account, and the notification endpoints, are open to any signed-in
     * user by design — a user with no grants at all still reaches them.
     *
     * @throws Exception
     */
    #[DataProvider('alwaysAllowedActionProvider')]
    public function testSomeActionsAreOpenToAnySignedInUser(int $actionId): void
    {
        self::assertTrue($this->acl([])->checkUserAccess($actionId));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function alwaysAllowedActionProvider(): array
    {
        return [
            'request an account' => [AclActionsInterface::ACCOUNT_REQUEST],
            'notifications' => [AclActionsInterface::NOTIFICATION],
            'view a notification' => [AclActionsInterface::NOTIFICATION_VIEW],
            'check notifications' => [AclActionsInterface::NOTIFICATION_CHECK],
        ];
    }

    /**
     * Anything the table does not name is refused. A permission check that fell through to an
     * allow would hand out access for every action added without a rule.
     *
     * @throws Exception
     */
    public function testAnUnknownActionIsRefused(): void
    {
        self::assertFalse($this->acl(['mgmUsers' => true, 'accView' => true])->checkUserAccess(-1));
    }

    /**
     * Without a profile there is nothing to authorise against, so everything is refused —
     * including for a user who would otherwise be an account administrator.
     *
     * @throws Exception
     */
    public function testAUserWithoutAProfileIsRefused(): void
    {
        // The session context refuses a null profile, so this state is reachable only through a
        // double — which is the point: the guard exists for a context that has none.
        $context = $this->createStub(Context::class);
        $context->method('getUserProfile')->willReturn(null);
        $context->method('getUserData')->willReturn($this->context->getUserData()->mutate(['isAdminApp' => true]));

        $acl = new Acl($context, $this->application->getEventDispatcher(), $this->actions());

        self::assertFalse($acl->checkUserAccess(AclActionsInterface::ACCOUNT_VIEW));
    }

    /**
     * @param array<string, bool> $profile
     * @param array<string, mixed> $user
     *
     * @throws Exception
     */
    private function acl(array $profile, array $user = []): Acl
    {
        $this->context->setUserProfile(new ProfileData($profile));
        $this->context->setUserData(
            $this->context->getUserData()->mutate(
                array_merge(['isAdminApp' => false, 'isAdminAcc' => false], $user)
            )
        );

        return new Acl($this->context, $this->application->getEventDispatcher(), $this->actions());
    }

    /**
     * @throws Exception
     */
    private function actions(): ActionsInterface
    {
        return $this->createStub(ActionsInterface::class);
    }
}
