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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountToUserGroupService;
use SP\Application\Account\Ports\AccountToUserService;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\Crypt\Ports\MasterPassService;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Dtos\AccountHistoryViewDto;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Acl\UnauthorizedActionException;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Context\Context;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\User;
use SP\Domain\User\Services\UpdatedMasterPassException;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountHistoryHelper;
use SP\Infrastructure\Adapter\In\Web\DataGrid\Action\DataGridActionInterface;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\UI\ThemeIcons;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The history view is where an old, already-superseded version of an account is shown, and the
 * one action on it with a real consequence is restoring that version over the current one. That
 * has to require the same edit access as any other change to the account, or a viewer who can
 * only look at the account's past could reach in and overwrite its present.
 *
 * The integration suite cannot exercise a refusal here — its ACL is stubbed permanently open — so
 * both the page-level guard (initializeFor()'s access/master-password checks) and the
 * per-account guard (checkAccess()'s permission check) are pinned in this unit test instead, with
 * AccountAclService mocked closed.
 */
#[Group('unitary')]
class AccountHistoryHelperTest extends UnitaryTestCase
{
    private const ACCOUNT_ID = 42;
    private const HISTORY_ID = 77;

    private AclInterface|Stub $acl;
    private MasterPassService|Stub $masterPassService;
    private AccountAclService|Stub $accountAclService;
    private AccountHistoryService|Stub $accountHistoryService;
    private CategoryService|Stub $categoryService;
    private ClientService|Stub $clientService;
    private AccountToUserService|Stub $accountToUserService;
    private AccountToUserGroupService|Stub $accountToUserGroupService;

    /** @var array<string, mixed> What the template was handed, by name */
    private array $assigned = [];

    /**
     * Reaching the view at all requires the page-level access check to have already run and
     * granted it — a caller that skips initializeFor() (or one that failed it) must not be able
     * to render anything.
     */
    #[Test]
    public function theViewIsRefusedWithoutAPriorAccessGrant(): void
    {
        $this->expectException(UnauthorizedActionException::class);

        // initializeFor() deliberately not called: actionGranted stays false.
        $this->buildHelper()->setViewForAccount($this->buildDto());
    }

    /**
     * Passing the page-level check is not the same as being allowed to see this particular
     * account's history — that is a separate, per-account permission check.
     *
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     */
    #[Test]
    public function theViewIsRefusedWhenTheAccountItselfIsNotAccessible(): void
    {
        $helper = $this->helperGrantedAccessToTheHistoryPage();

        $denied = new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW, true);
        $denied->setCompiledAccountAccess(true);
        $denied->setResultView(false);

        $this->accountAclService->method('getAcl')->willReturn($denied);

        $this->expectException(AccountPermissionException::class);

        $helper->setViewForAccount($this->buildDto());
    }

    /**
     * Restoring is offered only on top of the underlying edit access. A viewer who may look at
     * the history but has no edit rights over the account must not be offered a way to write to
     * it.
     *
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     * @throws AccountPermissionException
     */
    #[Test]
    public function restoreIsNotOfferedToAViewerWhoMayNotEditTheAccount(): void
    {
        $helper = $this->helperGrantedAccessToTheHistoryPage();
        $this->accountAclService->method('getAcl')->willReturn($this->permission(canEdit: false));

        $helper->setViewForAccount($this->buildDto());

        self::assertNotContains(
            AclActionsInterface::ACCOUNT_EDIT_RESTORE,
            $this->assignedActionIds(),
            'a viewer without edit access on the account was offered a way to restore it'
        );
    }

    /**
     * With the underlying edit access granted, restoring the shown version is offered.
     *
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     * @throws AccountPermissionException
     */
    #[Test]
    public function restoreIsOfferedToAViewerWhoMayEditTheAccount(): void
    {
        $helper = $this->helperGrantedAccessToTheHistoryPage();
        $this->accountAclService->method('getAcl')->willReturn($this->permission(canEdit: true));

        $helper->setViewForAccount($this->buildDto());

        self::assertContains(
            AclActionsInterface::ACCOUNT_EDIT_RESTORE,
            $this->assignedActionIds(),
            'a viewer with edit access on the account was not offered a way to restore it'
        );
    }

    /**
     * Every history entry but the first was actually edited, so it is labelled with whoever
     * changed it and when.
     */
    #[Test]
    public function anEditedEntryIsLabelledWithItsEditor(): void
    {
        $entry = new Simple(
            [
                'id' => 5,
                'dateAdd' => '2024-01-01 10:00:00',
                'userAdd' => 'creator',
                'dateEdit' => '2024-02-02 11:00:00',
                'userEdit' => 'editor',
            ]
        );

        self::assertSame(
            ['5' => '2024-02-02 11:00:00 - editor'],
            AccountHistoryHelper::mapHistoryForDateSelect([$entry])
        );
    }

    /**
     * The very first entry in an account's history has no editor or edit date yet — it is
     * labelled with whoever created the account instead, not with an empty edit date.
     */
    #[Test]
    public function theFirstEntryIsLabelledWithItsCreator(): void
    {
        $entry = new Simple(
            [
                'id' => 5,
                'dateAdd' => '2024-01-01 10:00:00',
                'userAdd' => 'creator',
                'dateEdit' => null,
                'userEdit' => null,
            ]
        );

        self::assertSame(
            ['5' => '2024-01-01 10:00:00 - creator'],
            AccountHistoryHelper::mapHistoryForDateSelect([$entry])
        );
    }

    /**
     * A zeroed-out edit date (what the schema stores for "never edited") is read the same as an
     * empty one, not as a real edit that happened at the epoch.
     */
    #[Test]
    public function aZeroedEditDateIsTreatedAsNeverEdited(): void
    {
        $entry = new Simple(
            [
                'id' => 5,
                'dateAdd' => '2024-01-01 10:00:00',
                'userAdd' => 'creator',
                'dateEdit' => '0000-00-00 00:00:00',
                'userEdit' => '',
            ]
        );

        self::assertSame(
            ['5' => '2024-01-01 10:00:00 - creator'],
            AccountHistoryHelper::mapHistoryForDateSelect([$entry])
        );
    }

    /**
     * The context has to be session-backed — HelperBase refuses anything else — and carries the
     * signed-in user the actions helper reads for the overflow menu.
     */
    protected function buildContext(): Context
    {
        $context = self::createStub(SessionContext::class);
        $context->method('getUserData')->willReturn(
            UserDto::fromModel(new User(['id' => 1, 'login' => 'someone']))
        );

        return $context;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->acl = $this->createStub(AclInterface::class);
        $this->acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $this->masterPassService = $this->createStub(MasterPassService::class);
        $this->accountAclService = $this->createStub(AccountAclService::class);
        $this->accountHistoryService = $this->createStub(AccountHistoryService::class);
        $this->accountHistoryService->method('getHistoryForAccount')->willReturn([]);
        $this->categoryService = $this->createStub(CategoryService::class);
        $this->categoryService->method('getAll')->willReturn([]);
        $this->clientService = $this->createStub(ClientService::class);
        $this->clientService->method('getAll')->willReturn([]);
        $this->accountToUserService = $this->createStub(AccountToUserService::class);
        $this->accountToUserService->method('getUsersByAccountId')->willReturn([]);
        $this->accountToUserGroupService = $this->createStub(AccountToUserGroupService::class);
        $this->accountToUserGroupService->method('getUserGroupsByAccountId')->willReturn([]);
    }

    private function buildHelper(): AccountHistoryHelper
    {
        $view = $this->createStub(TemplateInterface::class);
        $view->method('assign')
             ->willReturnCallback(function (string $name, mixed $value) {
                 $this->assigned[$name] = $value;
             });

        $request = $this->createStub(RequestService::class);

        $accountActionsHelper = new AccountActionsHelper(
            $this->application,
            $view,
            $request,
            new ThemeIcons(),
            $this->acl
        );

        return new AccountHistoryHelper(
            $this->application,
            $view,
            $request,
            $this->acl,
            $accountActionsHelper,
            $this->masterPassService,
            $this->accountHistoryService,
            $this->accountAclService,
            $this->categoryService,
            $this->clientService,
            $this->accountToUserService,
            $this->accountToUserGroupService
        );
    }

    /**
     * A helper that already passed the page-level guard (initializeFor()) — the access and
     * master-password checks a real request goes through before AccountHistoryHelper is asked to
     * render anything.
     *
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     */
    private function helperGrantedAccessToTheHistoryPage(): AccountHistoryHelper
    {
        $this->acl->method('checkUserAccess')->willReturn(true);
        $this->masterPassService->method('checkUserUpdateMPass')->willReturn(true);

        $helper = $this->buildHelper();
        $helper->initializeFor(AclActionsInterface::ACCOUNT_HISTORY_VIEW);

        return $helper;
    }

    private function permission(bool $canEdit): AccountPermission
    {
        $permission = new AccountPermission(AclActionsInterface::ACCOUNT_HISTORY_VIEW, true);
        $permission->setCompiledAccountAccess(true);
        $permission->setResultView(true);
        $permission->setResultEdit($canEdit);
        $permission->setShowRestore(true);

        return $permission;
    }

    private function buildDto(): AccountHistoryViewDto
    {
        return new AccountHistoryViewDto(
            userId: 1,
            userGroupId: 1,
            dateEdit: '2024-01-01 10:00:00',
            accountId: self::ACCOUNT_ID,
            id: self::HISTORY_ID,
            passDateChange: 0,
            categoryId: 1,
            clientId: 1,
            passDate: 0
        );
    }

    /**
     * @return int[]
     */
    private function assignedActionIds(): array
    {
        /** @var DataGridActionInterface[] $actions */
        $actions = $this->assigned['accountActions'] ?? [];

        return array_map(static fn(DataGridActionInterface $action) => (int)$action->getId(), $actions);
    }
}
