<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\User\Models\ProfileData;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\AccountForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountFormTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class AccountFormTest extends UnitaryTestCase
{
    /**
     * An action the form does not handle must fail as a ValidationException,
     * not an \UnhandledMatchError (which would surface as a 500).
     */
    public function testValidateForUnhandledActionThrowsValidationException(): void
    {
        $form = new AccountForm(
            $this->application,
            $this->createMock(RequestService::class),
            $this->createMock(AccountPresetService::class)
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid action');

        $form->validateFor(AclActionsInterface::ACCOUNT_DELETE);
    }

    /**
     * Dto::fromArray() matches constructor parameter names, so the array this form builds has to be
     * keyed by them. It used 'private'/'privateGroup' while AccountDto declares $isPrivate and
     * $isPrivateGroup, so both flags arrived null and every account was stored with the privacy the
     * form asked for silently dropped — the checkbox did nothing, on create and on edit.
     */
    public function testPrivateFlagsReachTheDto(): void
    {
        // The form now holds both flags to the profile permission and to ownership, the way the
        // interface does when it decides whether to offer the checkbox — so the user this runs as
        // has to be one the flags are actually available to.
        $this->context->setUserProfile(new ProfileData(['accPrivate' => true, 'accPrivateGroup' => true]));

        $userData = $this->context->getUserData();

        $request = $this->createMock(RequestService::class);

        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => $param === 'name' ? 'an_account' : null
        );
        // owner_id and main_usergroup_id carry the signed-in user: the real analyzeInt() falls back
        // to that default, and the privacy flags are only available to somebody marking their own.
        $request->method('analyzeInt')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'client_id', 'category_id' => 1,
                'owner_id' => $userData->id,
                'main_usergroup_id' => $userData->userGroupId,
                default => null,
            }
        );
        $request->method('analyzeEncrypted')->willReturn('a_password');
        $request->method('analyzeBool')->willReturnCallback(
            static fn(string $param) => in_array($param, ['private_enabled', 'private_group_enabled'], true)
        );

        $accountPresetService = $this->createMock(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);
        $accountPresetService->method('checkPasswordExpiry')->willReturnArgument(0);

        $form = new AccountForm($this->application, $request, $accountPresetService);

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNT_CREATE)->getItemData();

        // AccountDto types both as ?bool, so the form's int cast arrives coerced.
        self::assertTrue($accountDto->isPrivate);
        self::assertTrue($accountDto->isPrivateGroup);
    }

    /**
     * The interface only offers the privacy checkboxes to a user whose profile carries the
     * permission — `AccountHelper` computes `allowPrivate` / `allowPrivateGroup` for exactly that —
     * but nothing looked at either flag on the way back in, so a request carrying `private_enabled`
     * anyway was honoured.
     *
     * Neither flag reaches anything: both only ever withhold. What they do is hide, and `AccountAcl`
     * tests privacy before the administrator branch, so an account marked private disappears for
     * account administrators as well.
     */
    public function testPrivateFlagsAreRefusedWithoutThePermission(): void
    {
        $this->context->setUserProfile(new ProfileData());

        $request = $this->createMock(RequestService::class);

        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => $param === 'name' ? 'an_account' : null
        );
        $request->method('analyzeInt')->willReturnCallback(
            static fn(string $param) => in_array($param, ['client_id', 'category_id'], true) ? 1 : null
        );
        $request->method('analyzeEncrypted')->willReturn('a_password');
        $request->method('analyzeBool')->willReturnCallback(
            static fn(string $param) => in_array($param, ['private_enabled', 'private_group_enabled'], true)
        );

        $accountPresetService = $this->createMock(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);
        $accountPresetService->method('checkPasswordExpiry')->willReturnArgument(0);

        $form = new AccountForm($this->application, $request, $accountPresetService);

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNT_CREATE)->getItemData();

        self::assertFalse($accountDto->isPrivate);
        self::assertFalse($accountDto->isPrivateGroup);
    }

    /**
     * An account with no name would be unidentifiable in the listing/search; checkCommon()
     * must refuse it rather than let a nameless row reach the database.
     */
    public function testMissingNameIsRejected(): void
    {
        $form = $this->buildForm($this->mockAccountRequest(strings: ['name' => null]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('An account name needed');

        $form->validateFor(AclActionsInterface::ACCOUNT_CREATE);
    }

    /**
     * Every account is billed/organised under a client; without this guard an account
     * could be saved with no client_id, breaking every client-scoped ACL/listing that
     * assumes the column is always populated.
     */
    public function testMissingClientIsRejected(): void
    {
        $form = $this->buildForm($this->mockAccountRequest(ints: ['client_id' => null]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A client is needed');

        $form->validateFor(AclActionsInterface::ACCOUNT_CREATE);
    }

    /**
     * Same as the client guard, for the category: accounts are grouped/ACL'd by
     * category, so a missing category_id must be rejected rather than stored.
     */
    public function testMissingCategoryIsRejected(): void
    {
        $form = $this->buildForm($this->mockAccountRequest(ints: ['category_id' => null]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A category is needed');

        $form->validateFor(AclActionsInterface::ACCOUNT_CREATE);
    }

    /**
     * A blank password must be rejected for a top-level account — an empty pass column
     * would leave the vault entry undecryptable/meaningless.
     */
    public function testBlankPasswordIsRejected(): void
    {
        $form = $this->buildForm($this->mockAccountRequest());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A key is needed');

        $form->validateFor(AclActionsInterface::ACCOUNT_CREATE);
    }

    /**
     * A password/repeat mismatch must be rejected — otherwise a typo in the repeat
     * field would silently store a password different from the one the user believes
     * they set, locking them out of their own account.
     */
    public function testPasswordMismatchIsRejected(): void
    {
        $form = $this->buildForm(
            $this->mockAccountRequest(password: 'secretA', passwordRepeat: 'secretB')
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Passwords do not match');

        $form->validateFor(AclActionsInterface::ACCOUNT_CREATE);
    }

    /**
     * checkPassword() skips its own checks entirely when parentId > 0: a "child"
     * account inherits its password from the parent, so requiring one here would make
     * it impossible to create a linked account with a blank password field.
     */
    public function testChildAccountSkipsPasswordChecks(): void
    {
        $accountPresetService = $this->createStub(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);
        $accountPresetService->method('checkPasswordExpiry')->willReturnArgument(0);

        $form = new AccountForm(
            $this->application,
            $this->mockAccountRequest(ints: ['parent_account_id' => 5]),
            $accountPresetService
        );

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNT_CREATE)->getItemData();

        $this->assertSame(5, $accountDto->parentId);
        $this->assertNull($accountDto->pass);
    }

    /**
     * ACCOUNT_EDIT_PASS is a distinct switch arm (checkPassword + checkPasswordPreset,
     * no analyzeItems/checkCommon) that no other test reaches; a valid, matching
     * password pair on an existing account must be accepted.
     */
    public function testEditPassSucceedsWithMatchingPasswords(): void
    {
        $accountPresetService = $this->createStub(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);
        $accountPresetService->method('checkPasswordExpiry')->willReturnArgument(0);

        $form = new AccountForm(
            $this->application,
            $this->mockAccountRequest(password: 'newSecret', passwordRepeat: 'newSecret'),
            $accountPresetService
        );

        $result = $form->validateFor(AclActionsInterface::ACCOUNT_EDIT_PASS, 42);

        $this->assertSame($form, $result);
        $this->assertSame('newSecret', $form->getItemData()->pass);
    }

    /**
     * ACCOUNT_EDIT is a distinct switch arm (analyzeItems + checkCommon, no
     * checkPassword/checkPasswordPreset) that no other test reaches. It also exercises
     * three of analyzeItems()'s five independent "*_update === 1" branches: without the
     * "=== 1" flag, a submitted permission array must be silently ignored (Chainable
     * carries the *previous* dto forward — a bug here would either drop legitimate
     * permission edits or apply them when the operator didn't ask to change them).
     */
    public function testEditUpdatesOnlyFlaggedPermissionLists(): void
    {
        $form = $this->buildForm(
            $this->mockAccountRequest(
                ints: [
                    'other_users_view_update' => 1,
                    'other_usergroups_edit_update' => 1,
                    'tags_update' => 1,
                    // Deliberately NOT 1: must be left untouched (stays null).
                    'other_users_edit_update' => 0,
                ],
                arrays: [
                    'other_users_view' => [11, 12],
                    'other_usergroups_edit' => [21],
                    'tags' => [31, 32, 33],
                    'other_users_edit' => [99],
                ]
            )
        );

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNT_EDIT, 42)->getItemData();

        $this->assertSame([11, 12], $accountDto->usersView);
        $this->assertSame([21], $accountDto->userGroupsEdit);
        $this->assertSame([31, 32, 33], $accountDto->tags);
        $this->assertNull($accountDto->usersEdit, 'update flag was not 1, list must be left untouched');
    }

    /**
     * ACCOUNTMGR_BULK_EDIT is a distinct switch arm (analyzeItems + analyzeBulkEdit)
     * that no other test reaches. It exercises analyzeBulkEdit()'s four independent
     * "clear_permission_*" branches on BOTH sides: only two of the four flags are set,
     * so a bulk edit must clear exactly the lists it was told to and leave the other
     * two (populated moments earlier by analyzeItems()) untouched — a bug that ORs the
     * conditions together, or clears unconditionally, would either wipe permissions the
     * operator did not ask to touch or fail to clear the ones they did.
     */
    public function testBulkEditClearsOnlyFlaggedPermissionLists(): void
    {
        $form = $this->buildForm(
            $this->mockAccountRequest(
                ints: [
                    'other_users_edit_update' => 1,
                    'other_usergroups_view_update' => 1,
                ],
                arrays: [
                    'other_users_edit' => [40],
                    'other_usergroups_view' => [50],
                ],
                bools: [
                    'clear_permission_users_view' => true,
                    'clear_permission_usergroups_edit' => true,
                    // clear_permission_users_edit / clear_permission_usergroups_view
                    // deliberately left false (default): must NOT clear those lists.
                ]
            )
        );

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNTMGR_BULK_EDIT, 42)->getItemData();

        $this->assertSame([], $accountDto->usersView, 'flagged for clearing, was never set by analyzeItems');
        $this->assertSame([], $accountDto->userGroupsEdit, 'flagged for clearing, was never set by analyzeItems');
        $this->assertSame([40], $accountDto->usersEdit, 'not flagged for clearing, must keep analyzeItems() value');
        $this->assertSame(
            [50],
            $accountDto->userGroupsView,
            'not flagged for clearing, must keep analyzeItems() value'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildForm(RequestService $request): AccountForm
    {
        $accountPresetService = $this->createStub(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);
        $accountPresetService->method('checkPasswordExpiry')->willReturnArgument(0);

        return new AccountForm($this->application, $request, $accountPresetService);
    }

    /**
     * Configures a RequestService stub with valid defaults for name/client/category
     * (so checkCommon() passes); password/password_repeat default to null (blank),
     * letting each test override just the field(s) it wants to exercise.
     *
     * @param array<string, int|null> $ints
     * @param array<string, string|null> $strings
     * @param array<string, bool> $bools
     * @param array<string, array|null> $arrays
     */
    private function mockAccountRequest(
        array $ints = [],
        array $strings = [],
        array $bools = [],
        array $arrays = [],
        ?string $password = null,
        ?string $passwordRepeat = null
    ): RequestService|MockObject {
        $ints = array_merge(['client_id' => 1, 'category_id' => 1], $ints);
        $strings = array_merge(['name' => 'an_account'], $strings);

        $request = $this->createStub(RequestService::class);

        $request->method('analyzeInt')->willReturnCallback(
            static fn(string $param, ?int $default = null) => array_key_exists($param, $ints)
                ? $ints[$param]
                : $default
        );
        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param, ?string $default = null) => array_key_exists($param, $strings)
                ? $strings[$param]
                : $default
        );
        $request->method('analyzeBool')->willReturnCallback(
            static fn(string $param, ?bool $default = false) => $bools[$param] ?? $default
        );
        $request->method('analyzeArray')->willReturnCallback(
            static fn(string $param, ?callable $mapper = null, mixed $default = null) => $arrays[$param] ?? $default
        );
        $request->method('analyzeUnsafeString')->willReturn(null);
        $request->method('analyzeEncrypted')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'password' => $password,
                'password_repeat' => $passwordRepeat,
                default => null,
            }
        );

        return $request;
    }
}
