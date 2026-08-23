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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Account\Ports\AccountAclService;
use SP\Application\Account\Ports\AccountHistoryService;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\AccountToUserGroupService;
use SP\Application\Account\Ports\AccountToUserService;
use SP\Application\Account\Ports\PublicLinkService;
use SP\Application\Application;
use SP\Application\Category\Ports\CategoryService;
use SP\Application\Client\Ports\ClientService;
use SP\Application\Crypt\Ports\MasterPassService;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Application\Tag\Ports\TagService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Account\Adapters\AccountPermission;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Auth\Providers\Browser\BrowserAuthService;
use SP\Domain\Core\Acl\AccountPermissionException;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Acl\UnauthorizedPageException;
use SP\Domain\Core\Bootstrap\RouteContextData;
use SP\Domain\Core\Bootstrap\UriContextInterface;
use SP\Domain\Core\Crypt\CryptPKIHandler;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\CopyController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\DeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\EditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\EditPassController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\RequestAccessController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\SaveDeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\SaveEditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\SaveEditPassController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\SaveEditRestoreController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\ViewController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Account\ViewHistoryController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountActionsHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountAclEnforcer;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountHistoryHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Account\AccountRequestHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\LayoutHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\SimpleControllerHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\WebControllerHelper;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Infrastructure\Bootstrap\Router;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\PhpExtensionChecker;
use SP\Infrastructure\UI\ThemeIcons;
use SP\Tests\Support\WebControllerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * What these actions do when access is refused.
 *
 * None of the controllers here check the ACL themselves and return an `ActionResponse::error()` the
 * way `Notification` or `Track` do. Two different collaborators do the check instead, and both
 * throw rather than return:
 *
 * - `Copy`, `Create`, `Delete`, `Edit`, `EditPass` and `View` call `AccountHelper::initializeFor()`
 *   (via `AccountViewBase`/`AccountControllerBase`); `ViewHistory` calls the same method on
 *   `AccountHistoryHelper`; `RequestAccess` calls it on `AccountRequestHelper`. All three extend
 *   `AccountHelperBase`, whose `initializeFor()` calls `$acl->checkUserAccess()` first, before
 *   touching any of its own dependencies, and throws `UnauthorizedPageException` when it is false.
 *   The three helper classes are declared `final`, so a test cannot mock one closed — it builds a
 *   real instance instead, with every other dependency stubbed, since none of them is ever asked
 *   for anything before the exception is thrown.
 * - `SaveDelete`, `SaveEdit`, `SaveEditPass` and `SaveEditRestore` (the account *mutation* actions)
 *   call `AccountAclEnforcer::checkAccountAccess()`, which is the fix for the IDOR the class's own
 *   docblock describes: these used to call the service directly with no per-account check at all.
 *   It resolves the account's ACL through `AccountAclService::getAcl()` and throws
 *   `AccountPermissionException` when `AccountPermission::checkAccountAccess()` says no — which is
 *   what a fresh `AccountPermission` says by default, so the stub below needs no configuring either.
 *
 * Because every refusal here is an exception and not a returned `ActionResponse`, none of these
 * actions has a `catch` arm to exercise — unlike `Notification`/`Track`, there is no "reports a
 * failure behind it" test in this file. `ViewLinkController` is the one Account controller that
 * does carry a `try`/`catch`, and it is already covered by the integration suite's
 * `ViewLinkRefusalsTest`; it is not repeated here.
 *
 * Controllers with no guard at all are also left out on purpose: `CopyPass`, `CopyPassHistory`,
 * `ViewPass` and `ViewPassHistory` rely on `AccountService::getPasswordForId()`/
 * `getPasswordHistoryForId()` filtering by visibility rather than on a `checkUserAccess()`-style
 * guard; `Index` and `Search` ask nothing beyond being logged in; and `SaveCopy`, `SaveCreate` and
 * `SaveRequest` have no access check at all — creating an account needs no per-account permission
 * because none yet exists, and `SaveRequest` only records a request, so nothing here would ever be
 * refused by an ACL check that does not exist.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function copyingIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new CopyController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'copy'),
            $accountService,
            $this->accountHelperThatRefuses($application)
        ))->copyAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new CreateController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'create'),
            $accountService,
            $this->accountHelperThatRefuses($application)
        ))->createAction();
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new DeleteController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'delete'),
            $this->accountHelperThatRefuses($application),
            $accountService
        ))->deleteAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new EditController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'edit'),
            $accountService,
            $this->accountHelperThatRefuses($application)
        ))->editAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingPasswordIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new EditPassController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'editPass'),
            $accountService,
            $this->accountHelperThatRefuses($application)
        ))->editPassAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new ViewController(
            $application,
            $this->accountViewWebControllerHelper($acl, $application, 'view'),
            $accountService,
            $this->accountHelperThatRefuses($application)
        ))->viewAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingHistoryIsRefusedWhenTheAclDenies(): void
    {
        $accountHistoryService = $this->createMock(AccountHistoryService::class);
        $accountHistoryService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new ViewHistoryController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'viewHistory'),
            $accountHistoryService,
            $this->accountHistoryHelperThatRefuses($application)
        ))->viewHistoryAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function requestingAccessIsRefusedWhenTheAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('getByIdEnriched');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $this->expectException(UnauthorizedPageException::class);

        (new RequestAccessController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'requestAccess'),
            $accountService,
            $this->accountRequestHelperThatRefuses($application)
        ))->requestAccessAction(1);
    }

    /**
     * Object-level (per-account) refusal, not the general ACL: `AccountAclEnforcer` is what
     * `SaveDelete`/`SaveEdit`/`SaveEditPass`/`SaveEditRestore` ask, so the ACL bound to the
     * controller itself is left allowing everything to make that distinction clear.
     *
     * @throws Exception
     */
    #[Test]
    public function savingADeleteIsRefusedWhenTheAccountAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('delete');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $this->expectException(AccountPermissionException::class);

        (new SaveDeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'saveDelete'),
            $accountService,
            $this->createStub(CustomFieldDataService::class),
            $this->accountAclEnforcerThatRefuses()
        ))->saveDeleteAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAccountAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('update');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $this->expectException(AccountPermissionException::class);

        (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'saveEdit'),
            $accountService,
            $this->createStub(AccountPresetService::class),
            $this->createStub(CustomFieldDataService::class),
            $this->accountAclEnforcerThatRefuses()
        ))->saveEditAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditPasswordIsRefusedWhenTheAccountAclDenies(): void
    {
        $accountService = $this->createMock(AccountService::class);
        $accountService->expects(self::never())->method('editPassword');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $this->expectException(AccountPermissionException::class);

        (new SaveEditPassController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'saveEditPass'),
            $accountService,
            $this->createStub(AccountPresetService::class),
            $this->accountAclEnforcerThatRefuses()
        ))->saveEditPassAction(1);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingARestoreIsRefusedWhenTheAccountAclDenies(): void
    {
        $accountHistoryService = $this->createMock(AccountHistoryService::class);
        $accountHistoryService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $this->expectException(AccountPermissionException::class);

        (new SaveEditRestoreController(
            $application,
            $this->webControllerHelper($acl, $application, 'account', 'saveEditRestore'),
            $this->createStub(AccountService::class),
            $accountHistoryService,
            $this->accountAclEnforcerThatRefuses()
        ))->saveEditRestoreAction(2, 1);
    }

    /**
     * Mirrors `WebControllerTestCase::webControllerHelper()`, with one addition: `AccountViewBase`
     * and `DeleteController` both declare `protected readonly ThemeIcons $icons` — the concrete
     * class, not `ThemeIconsInterface` — and assign it from `$this->theme->getIcons()` in their own
     * constructor, straight after `parent::__construct()`. The shared harness leaves `getIcons()`
     * unconfigured, so PHPUnit's stub answers with a `ThemeIconsInterface` double rather than a
     * `ThemeIcons`, and assigning it is a `TypeError` — before the ACL is ever consulted, so it
     * would otherwise mask the very refusal these tests exist to show. `ThemeIcons` is `final` with
     * no constructor, so a real, empty one satisfies the type without needing to be doubled.
     *
     * @throws Exception
     */
    private function accountViewWebControllerHelper(
        AclInterface $acl,
        Application  $application,
        string       $action
    ): WebControllerHelper {
        $request = $this->createStub(RequestService::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('getServer')->willReturn('0');
        $request->method('analyzeString')->willReturn(null);
        $request->method('analyzeArray')->willReturn(null);
        $request->method('analyzeInt')->willReturn(null);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getUri')->willReturn('/theme');
        $theme->method('getIcons')->willReturn(new ThemeIcons());

        $uriContext = $this->createStub(UriContextInterface::class);
        $uriContext->method('getWebRoot')->willReturn('https://syspass.invalid');
        $uriContext->method('getSubUri')->willReturn('/index.php');

        $template = $this->createStub(TemplateInterface::class);

        $layoutHelper = new LayoutHelper(
            $application,
            $template,
            $request,
            $theme,
            $this->createStub(CryptPKIHandler::class),
            $uriContext,
            $acl
        );

        $simpleControllerHelper = new SimpleControllerHelper(
            $theme,
            new Router(new Request(), $this->createStub(ResponseService::class)),
            $acl,
            $request,
            new PhpExtensionChecker(),
            $uriContext,
            new RouteContextData('account', $action, $action . 'Action', [])
        );

        return new WebControllerHelper(
            $simpleControllerHelper,
            $template,
            $this->createStub(BrowserAuthService::class),
            $layoutHelper
        );
    }

    /**
     * `AccountHelper` is what `Copy`, `Create`, `Delete`, `Edit`, `EditPass` and `View` ask before
     * doing anything else. Every dependency below is a stub, and none is ever asked for anything:
     * `initializeFor()` calls `$acl->checkUserAccess()` first and throws before reaching them.
     *
     * @throws Exception
     */
    private function accountHelperThatRefuses(Application $application): AccountHelper
    {
        $template = $this->createStub(TemplateInterface::class);
        $request = $this->createStub(RequestService::class);
        $acl = $this->aclThatRefuses();

        return new AccountHelper(
            $application,
            $template,
            $request,
            $acl,
            $this->createStub(AccountService::class),
            $this->createStub(AccountHistoryService::class),
            $this->createStub(PublicLinkService::class),
            $this->createStub(ItemPresetService::class),
            $this->createStub(MasterPassService::class),
            $this->accountActionsHelper($application, $template, $request, $acl),
            $this->createStub(AccountAclService::class),
            $this->createStub(CategoryService::class),
            $this->createStub(ClientService::class),
            $this->createStub(CustomFieldDataService::class),
            $this->createStub(UserService::class),
            $this->createStub(UserGroupService::class),
            $this->createStub(TagService::class),
            $this->createStub(UriContextInterface::class)
        );
    }

    /**
     * Mirrors `accountHelperThatRefuses()` for `ViewHistoryController`, which asks
     * `AccountHistoryHelper` instead.
     *
     * @throws Exception
     */
    private function accountHistoryHelperThatRefuses(Application $application): AccountHistoryHelper
    {
        $template = $this->createStub(TemplateInterface::class);
        $request = $this->createStub(RequestService::class);
        $acl = $this->aclThatRefuses();

        return new AccountHistoryHelper(
            $application,
            $template,
            $request,
            $acl,
            $this->accountActionsHelper($application, $template, $request, $acl),
            $this->createStub(MasterPassService::class),
            $this->createStub(AccountHistoryService::class),
            $this->createStub(AccountAclService::class),
            $this->createStub(CategoryService::class),
            $this->createStub(ClientService::class),
            $this->createStub(AccountToUserService::class),
            $this->createStub(AccountToUserGroupService::class)
        );
    }

    /**
     * Mirrors `accountHelperThatRefuses()` for `RequestAccessController`, which asks
     * `AccountRequestHelper` instead.
     *
     * @throws Exception
     */
    private function accountRequestHelperThatRefuses(Application $application): AccountRequestHelper
    {
        $template = $this->createStub(TemplateInterface::class);
        $request = $this->createStub(RequestService::class);
        $acl = $this->aclThatRefuses();

        return new AccountRequestHelper(
            $application,
            $template,
            $request,
            $acl,
            $this->accountActionsHelper($application, $template, $request, $acl),
            $this->createStub(MasterPassService::class)
        );
    }

    /**
     * `AccountActionsHelper` is itself `final` and a constructor dependency of every helper above;
     * a real instance is built rather than mocked, for the same reason as the helpers themselves.
     *
     * @throws Exception
     */
    private function accountActionsHelper(
        Application       $application,
        TemplateInterface $template,
        RequestService    $request,
        AclInterface      $acl
    ): AccountActionsHelper {
        return new AccountActionsHelper(
            $application,
            $template,
            $request,
            $this->createStub(ThemeIconsInterface::class),
            $acl
        );
    }

    /**
     * `AccountAclEnforcer` is `final`, so this builds a real one rather than mocking it closed. Its
     * two dependencies are interfaces: `AccountService` is stubbed just enough to reach the ACL
     * lookup (an `AccountView` with the ids `AccountAclDto::makeFromAccount()` reads as non-nullable
     * ints), and `AccountAclService::getAcl()` returns a fresh `AccountPermission`, which refuses by
     * default — `checkAccountAccess()` is `false` until something calls one of its `setResult*()`
     * setters, which nothing here does.
     *
     * @throws Exception
     */
    private function accountAclEnforcerThatRefuses(): AccountAclEnforcer
    {
        $accountService = $this->createStub(AccountService::class);
        $accountService->method('getByIdEnriched')->willReturn(
            new AccountView(['id' => 1, 'userId' => 1, 'userGroupId' => 1])
        );
        $accountService->method('withUsers')->willReturnArgument(0);
        $accountService->method('withUserGroups')->willReturnArgument(0);

        $accountAclService = $this->createStub(AccountAclService::class);
        $accountAclService->method('getAcl')->willReturn(new AccountPermission(0));

        return new AccountAclEnforcer($accountService, $accountAclService);
    }
}
