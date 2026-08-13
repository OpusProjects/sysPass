<?php
declare(strict_types=1);
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

namespace SP\Tests\Unit\Domain\Account\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Tests\Support\UnitaryTestCase;

/**
 * AccountPermission is what an account page's buttons are compiled from -- edit, delete, view
 * the password, restore from history, and so on. Most of its "show" setters are guarded: a flag
 * only sticks once the underlying access result (resultView/resultEdit) already allows it, and
 * several of the matching getters additionally require the permission to have been built for one
 * particular ACL action. A flag that silently fails to stick is how a button goes missing from a
 * page a user should see it on; a flag that silently sticks anyway is how a button leaks onto a
 * page it should not appear on. That interaction, not the plain getters/setters, is what this
 * class exists to get right, so it is what these tests exercise.
 */
#[Group('unitary')]
class AccountPermissionTest extends UnitaryTestCase
{
    #[Test]
    public function showEditOnlySticksWhenResultEditIsAlreadyTrue(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultEdit(false)
            ->setShowEdit(true);

        self::assertFalse(
            $denied->isShowEdit(),
            'the edit button must not appear for a user whose access check denied editing'
        );

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultEdit(true)
            ->setShowEdit(true);

        self::assertTrue(
            $allowed->isShowEdit(),
            'once the access result allows editing, asking to show the edit button must work'
        );
    }

    /**
     * A history row is a past revision of an account, browsed read-only. Even a user who is
     * otherwise allowed to edit the live account must not get an edit button while looking at
     * one of its old revisions.
     */
    #[Test]
    public function showEditNeverSticksOnAHistoryRecordEvenWithEditAccess(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW, true))
            ->setResultEdit(true)
            ->setShowEdit(true);

        self::assertFalse($permission->isShowEdit());
    }

    #[Test]
    #[DataProvider('editActionProvider')]
    public function showEditOnlyAppearsOnTheActionsItApplies(int $actionId, bool $expected): void
    {
        $permission = (new AccountPermission($actionId))
            ->setResultEdit(true)
            ->setShowEdit(true);

        self::assertSame($expected, $permission->isShowEdit());
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function editActionProvider(): array
    {
        return [
            'the search listing' => [AclActionsInterface::ACCOUNT_SEARCH, true],
            'viewing the account' => [AclActionsInterface::ACCOUNT_VIEW, true],
            'the edit action itself' => [AclActionsInterface::ACCOUNT_EDIT, false],
            'deleting the account' => [AclActionsInterface::ACCOUNT_DELETE, false],
        ];
    }

    #[Test]
    public function showEditPassOnlySticksWhenResultEditIsAlreadyTrue(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT))
            ->setResultEdit(false)
            ->setShowEditPass(true);

        self::assertFalse($denied->isShowEditPass());

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT))
            ->setResultEdit(true)
            ->setShowEditPass(true);

        self::assertTrue($allowed->isShowEditPass());
    }

    /**
     * The stored password must not become editable just by browsing an old revision.
     */
    #[Test]
    public function showEditPassNeverSticksOnAHistoryRecord(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT, true))
            ->setResultEdit(true)
            ->setShowEditPass(true);

        self::assertFalse($permission->isShowEditPass());
    }

    #[Test]
    public function showEditPassOnlyAppliesToItsOwnActions(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_DELETE))
            ->setResultEdit(true)
            ->setShowEditPass(true);

        self::assertFalse(
            $permission->isShowEditPass(),
            'the delete action has no password field to edit, so the flag must not appear there'
        );
    }

    #[Test]
    public function showViewPassOnlySticksWhenResultViewIsAlreadyTrue(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW_PASS))
            ->setResultView(false)
            ->setShowViewPass(true);

        self::assertFalse(
            $denied->isShowViewPass(),
            'a user whose access check denied viewing must not get the view-password button'
        );

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW_PASS))
            ->setResultView(true)
            ->setShowViewPass(true);

        self::assertTrue($allowed->isShowViewPass());
    }

    #[Test]
    #[DataProvider('viewPassActionProvider')]
    public function showViewPassOnlyAppearsOnTheActionsItApplies(int $actionId, bool $expected): void
    {
        $permission = (new AccountPermission($actionId))
            ->setResultView(true)
            ->setShowViewPass(true);

        self::assertSame($expected, $permission->isShowViewPass());
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function viewPassActionProvider(): array
    {
        return [
            'the search listing' => [AclActionsInterface::ACCOUNT_SEARCH, true],
            'viewing the account' => [AclActionsInterface::ACCOUNT_VIEW, true],
            'the view-password action' => [AclActionsInterface::ACCOUNT_VIEW_PASS, true],
            'browsing a history revision' => [AclActionsInterface::ACCOUNT_HISTORY_VIEW, true],
            'editing the account' => [AclActionsInterface::ACCOUNT_EDIT, true],
            'deleting the account' => [AclActionsInterface::ACCOUNT_DELETE, false],
        ];
    }

    /**
     * Unlike showEdit/showEditPass, attachments are not blocked on a history revision -- browsing
     * an old version's files is legitimate, so only the resultView gate and the action apply.
     */
    #[Test]
    public function showFilesRequiresResultViewButIsNotBlockedOnHistory(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT))
            ->setResultView(false)
            ->setShowFiles(true);

        self::assertFalse($denied->isShowFiles());

        $onHistory = (new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW, true))
            ->setResultView(true)
            ->setShowFiles(true);

        self::assertTrue(
            $onHistory->isShowFiles(),
            'attachments on a past revision are viewable even though editing that revision is not'
        );

        $wrongAction = (new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH))
            ->setResultView(true)
            ->setShowFiles(true);

        self::assertFalse($wrongAction->isShowFiles(), 'the search listing row has nowhere to show attachments');
    }

    #[Test]
    public function showDeleteOnlySticksWhenResultEditIsAlreadyTrue(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_DELETE))
            ->setResultEdit(false)
            ->setShowDelete(true);

        self::assertFalse($denied->isShowDelete());

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_DELETE))
            ->setResultEdit(true)
            ->setShowDelete(true);

        self::assertTrue($allowed->isShowDelete());
    }

    #[Test]
    public function showDeleteOnlyAppliesToItsOwnActions(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW_PASS))
            ->setResultEdit(true)
            ->setShowDelete(true);

        self::assertFalse($permission->isShowDelete());
    }

    /**
     * Restoring a past revision only makes sense while looking at one -- unlike the other guarded
     * flags, isShowRestore() requires an exact action match rather than membership in a list.
     */
    #[Test]
    public function showRestoreRequiresBothResultEditAndTheHistoryViewAction(): void
    {
        $deniedByResult = (new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW))
            ->setResultEdit(false)
            ->setShowRestore(true);

        self::assertFalse($deniedByResult->isShowRestore());

        $wrongAction = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultEdit(true)
            ->setShowRestore(true);

        self::assertFalse($wrongAction->isShowRestore(), 'restoring only makes sense on the history-view action');

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW))
            ->setResultEdit(true)
            ->setShowRestore(true);

        self::assertTrue($allowed->isShowRestore());
    }

    #[Test]
    public function showCopyOnlySticksWhenResultViewIsAlreadyTrue(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_COPY))
            ->setResultView(false)
            ->setShowCopy(true);

        self::assertFalse($denied->isShowCopy());

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_COPY))
            ->setResultView(true)
            ->setShowCopy(true);

        // ACCOUNT_COPY itself is not among isShowCopy()'s matching actions (SEARCH/VIEW/EDIT),
        // so the copy button never shows on the copy screen itself.
        self::assertFalse($allowed->isShowCopy(), 'the copy action itself has no copy button on it');

        $onSearch = (new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH))
            ->setResultView(true)
            ->setShowCopy(true);

        self::assertTrue($onSearch->isShowCopy());
    }

    /**
     * Unlike its siblings, isShowView() is not restricted to specific actions -- only the
     * resultView gate applies, so once granted it follows the permission everywhere.
     */
    #[Test]
    public function showViewOnlySticksWhenResultViewIsAlreadyTrueAndHasNoActionGate(): void
    {
        $denied = (new AccountPermission(AclActionsInterface::ACCOUNT_DELETE))
            ->setResultView(false)
            ->setShowView(true);

        self::assertFalse($denied->isShowView());

        $allowed = (new AccountPermission(AclActionsInterface::ACCOUNT_DELETE))
            ->setResultView(true)
            ->setShowView(true);

        self::assertTrue($allowed->isShowView(), 'no action restricts isShowView(), unlike isShowEdit()');
    }

    /**
     * Unlike every other guarded setter, setShowHistory() does not gate on resultView/resultEdit
     * at all -- only the action check in isShowHistory() limits where it appears.
     */
    #[Test]
    public function showHistoryIsNotGatedByAnAccessResultOnlyByTheAction(): void
    {
        $matchingAction = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(false)
            ->setResultEdit(false)
            ->setShowHistory(true);

        self::assertTrue(
            $matchingAction->isShowHistory(),
            'setShowHistory() has no resultView/resultEdit guard, unlike setShowEdit()/setShowDelete()'
        );

        $wrongAction = (new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH))
            ->setShowHistory(true);

        self::assertFalse($wrongAction->isShowHistory(), 'the history tab only appears on view/history actions');
    }

    /**
     * The details panel follows the result and the action, not the setter: isShowDetails() never
     * reads the property setShowDetails() writes. Nothing in the application calls that setter, so
     * this is a vestigial one rather than a flag being ignored — pinned so the next person to reach
     * for it finds out here rather than from a panel that will not hide.
     */
    #[Test]
    #[DataProvider('booleanProvider')]
    public function showDetailsIgnoresWhatWasSetAndFollowsOnlyResultViewAndTheAction(bool $requested): void
    {
        $matching = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setShowDetails($requested);

        self::assertTrue($matching->isShowDetails(), 'true regardless of what setShowDetails() was given');

        $wrongAction = (new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH))
            ->setResultView(true)
            ->setShowDetails($requested);

        self::assertFalse($wrongAction->isShowDetails(), 'false regardless of what setShowDetails() was given');
    }

    /**
     * The password field follows the action alone: it is always shown when creating or copying an
     * account, since there is no account to inherit a password from, and never elsewhere.
     * setShowPass() writes a property nothing reads and is called from nowhere in the application —
     * pinned so that is discovered here rather than from a field that will not hide.
     */
    #[Test]
    #[DataProvider('booleanProvider')]
    public function showPassIgnoresWhatWasSetAndFollowsOnlyTheAction(bool $requested): void
    {
        $onCreate = (new AccountPermission(AclActionsInterface::ACCOUNT_CREATE))->setShowPass($requested);

        self::assertTrue($onCreate->isShowPass(), 'true regardless of what setShowPass() was given');

        $onView = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))->setShowPass($requested);

        self::assertFalse($onView->isShowPass(), 'false regardless of what setShowPass() was given');
    }

    /**
     * The save button likewise follows the action alone — and unlike its siblings it does not gate
     * on resultEdit. setShowSave() writes a property nothing reads and is called from nowhere in
     * the application.
     */
    #[Test]
    #[DataProvider('booleanProvider')]
    public function showSaveIgnoresWhatWasSetAndFollowsOnlyTheAction(bool $requested): void
    {
        $onEdit = (new AccountPermission(AclActionsInterface::ACCOUNT_EDIT))
            ->setResultEdit(false)
            ->setShowSave($requested);

        self::assertTrue(
            $onEdit->isShowSave(),
            'true on the edit action even with resultEdit false and regardless of what was set'
        );

        $onSearch = (new AccountPermission(AclActionsInterface::ACCOUNT_SEARCH))
            ->setResultEdit(true)
            ->setShowSave($requested);

        self::assertFalse($onSearch->isShowSave(), 'the search listing has no save button to show');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function booleanProvider(): array
    {
        return [
            'requested true' => [true],
            'requested false' => [false],
        ];
    }

    /**
     * isShow() is a summary flag ("does this row show anything at all") composed from a specific
     * subset of the guarded flags -- showView, showEdit, showViewPass, showCopy and showDelete.
     * Flags outside that subset (e.g. showPermission, showFiles) must not make it true on their
     * own, or a row with nothing a user can act on would still be rendered as actionable.
     */
    #[Test]
    public function isShowComposesOnlyItsSpecificSubsetOfFlags(): void
    {
        $nothingActionable = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setResultEdit(true)
            ->setShowFiles(true)
            ->setShowPermission(true)
            ->setShowHistory(true);

        self::assertFalse(
            $nothingActionable->isShow(),
            'showFiles/showPermission/showHistory are not part of isShow()\'s composed flags'
        );

        $withView = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setShowView(true);

        self::assertTrue($withView->isShow());
    }

    #[Test]
    public function checkAccountAccessIsAlwaysDeniedUntilCompiled(): void
    {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView(true)
            ->setResultEdit(true);

        self::assertFalse(
            $permission->checkAccountAccess(AclActionsInterface::ACCOUNT_VIEW),
            'an ACL that was never compiled must not grant access, whatever the result flags say'
        );

        $permission->setCompiledAccountAccess(true);

        self::assertTrue($permission->checkAccountAccess(AclActionsInterface::ACCOUNT_VIEW));
    }

    #[Test]
    #[DataProvider('checkAccountAccessProvider')]
    public function checkAccountAccessDispatchesToResultViewOrResultEditByAction(
        int $checkedAction,
        bool $resultView,
        bool $resultEdit,
        bool $expected
    ): void {
        $permission = (new AccountPermission(AclActionsInterface::ACCOUNT_VIEW))
            ->setResultView($resultView)
            ->setResultEdit($resultEdit)
            ->setCompiledAccountAccess(true);

        self::assertSame($expected, $permission->checkAccountAccess($checkedAction));
    }

    /**
     * @return array<string, array{int, bool, bool, bool}>
     */
    public static function checkAccountAccessProvider(): array
    {
        return [
            'a view action reads resultView, granted' => [AclActionsInterface::ACCOUNT_SEARCH, true, false, true],
            'a view action reads resultView, denied' => [AclActionsInterface::ACCOUNT_SEARCH, false, true, false],
            'an edit action reads resultEdit, granted' => [AclActionsInterface::ACCOUNT_EDIT, false, true, true],
            'an edit action reads resultEdit, denied' => [AclActionsInterface::ACCOUNT_EDIT, true, false, false],
            'an action in neither list is always denied' => [AclActionsInterface::ACCOUNT_CREATE, true, true, false],
        ];
    }

    #[Test]
    #[DataProvider('passthroughFlagProvider')]
    public function unguardedFlagsSimplyFollowWhatWasSet(string $setter, string $getter): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);

        self::assertFalse($permission->{$getter}(), 'defaults to false');

        $permission->{$setter}(true);

        self::assertTrue($permission->{$getter}());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function passthroughFlagProvider(): array
    {
        return [
            'user is in the matching groups' => ['setUserInGroups', 'isUserInGroups'],
            'user is in the matching users list' => ['setUserInUsers', 'isUserInUsers'],
            'the underlying view access result' => ['setResultView', 'isResultView'],
            'the underlying edit access result' => ['setResultEdit', 'isResultEdit'],
            'the ACL was modified from its default' => ['setModified', 'isModified'],
            'the public-link button' => ['setShowLink', 'isShowLink'],
            'the permissions button' => ['setShowPermission', 'isShowPermission'],
            'the compiled show-access cache flag' => ['setCompiledShowAccess', 'isCompiledShowAccess'],
            'the compiled account-access cache flag' => ['setCompiledAccountAccess', 'isCompiledAccountAccess'],
        ];
    }

    /**
     * The account id is what a compiled permission is cached and looked up by; unset it must read
     * as nothing rather than 0, since 0 is itself a value a lookup could be asked for.
     */
    #[Test]
    public function theAccountIdIsNothingUntilSet(): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);

        self::assertNull($permission->getAccountId());

        $permission->setAccountId(42);

        self::assertSame(42, $permission->getAccountId());
    }

    /**
     * The action id drives every guarded getter above, and it is set at construction but can
     * still be overridden -- the ACL compiler reuses one permission across several actions.
     */
    #[Test]
    public function theActionIdIsSetAtConstructionAndCanBeOverridden(): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);

        self::assertSame(AclActionsInterface::ACCOUNT_VIEW, $permission->getActionId());

        $permission->setActionId(AclActionsInterface::ACCOUNT_EDIT);

        self::assertSame(AclActionsInterface::ACCOUNT_EDIT, $permission->getActionId());
    }

    /**
     * The compilation timestamp is what a cached permission is checked for staleness against; a
     * permission that was never compiled must not claim one.
     */
    #[Test]
    public function theCompilationTimeDefaultsToZeroAndCanBeSet(): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);

        self::assertSame(0, $permission->getTime());

        $permission->setTime(1700000000);

        self::assertSame(1700000000, $permission->getTime());
    }

    /**
     * Every setter returns the same instance -- the ACL compiler builds a permission through a
     * long fluent chain of setShow*() calls, which relies on this.
     */
    #[Test]
    public function settersReturnTheSameInstanceForChaining(): void
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_VIEW);

        self::assertSame($permission, $permission->setResultView(true));
        self::assertSame($permission, $permission->setShowEdit(true));
    }
}
