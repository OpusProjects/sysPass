<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Application;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Config\Adapters\ConfigData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Events\EventDispatcherInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Adapter\In\Web\Forms\UserForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Tests that the admin-flag guard in UserForm works: a non-app-admin actor cannot
 * grant isAdminApp or isAdminAcc regardless of what the HTTP request contains.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class UserFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * A non-admin actor submitting adminapp_enabled=true and adminacc_enabled=true
     * must end up with both flags forced false on the persisted model.
     */
    public function testNonAdminCannotSetAdminFlags(): void
    {
        // Default context user is non-admin (isAdminApp = null / false).
        $this->mockRequestForUserEdit(adminAppEnabled: true, adminAccEnabled: true);

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::USER_EDIT, 10);

        $user = $form->getItemData();

        $this->assertFalse($user->isAdminApp(), 'non-admin actor must not grant isAdminApp');
        $this->assertFalse($user->isAdminAcc(), 'non-admin actor must not grant isAdminAcc');
    }

    /**
     * An app-admin actor submitting adminapp_enabled=true and adminacc_enabled=true
     * must have both flags honoured.
     */
    public function testAdminCanSetAdminFlags(): void
    {
        $this->setActorAsAdmin();
        $this->mockRequestForUserEdit(adminAppEnabled: true, adminAccEnabled: true);

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::USER_EDIT, 10);

        $user = $form->getItemData();

        $this->assertTrue($user->isAdminApp(), 'app-admin actor must be able to grant isAdminApp');
        $this->assertTrue($user->isAdminAcc(), 'app-admin actor must be able to grant isAdminAcc');
    }

    /**
     * An app-admin actor that submits adminapp_enabled=false must NOT have the flag
     * set to true (sanity: admin flag gating does not force-true, only gate).
     */
    public function testAdminRequestingFalseKeepsFalse(): void
    {
        $this->setActorAsAdmin();
        $this->mockRequestForUserEdit(adminAppEnabled: false, adminAccEnabled: false);

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::USER_EDIT, 10);

        $user = $form->getItemData();

        $this->assertFalse($user->isAdminApp());
        $this->assertFalse($user->isAdminAcc());
    }

    /**
     * Deleting your own account must be rejected.
     */
    public function testDeleteSelfIsRejected(): void
    {
        $form = $this->buildForm();

        $this->expectException(\SP\Domain\Core\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Unable to delete, user in use');

        $form->validateFor(AclActionsInterface::USER_DELETE, $this->context->getUserData()->id);
    }

    /**
     * Deleting another user passes the self-delete guard.
     */
    public function testDeleteOtherUserIsAllowed(): void
    {
        $form = $this->buildForm();

        $result = $form->validateFor(AclActionsInterface::USER_DELETE, $this->context->getUserData()->id + 1);

        $this->assertSame($form, $result);
    }

    /**
     * USER_CREATE is a distinct switch arm (analyzeRequestData + checkCommon +
     * checkPass) that no other test exercises; a submission with genuinely valid
     * data must go all the way through without being rejected by any guard.
     */
    public function testCreateNewUserSucceedsWithValidData(): void
    {
        $this->mockRequestFields(['password' => 'a-secret', 'password_repeat' => 'a-secret']);

        $form = $this->buildForm();
        $result = $form->validateFor(AclActionsInterface::USER_CREATE);

        $this->assertSame($form, $result);
        $this->assertSame('a-secret', $form->getItemData()->getPass());
    }

    /**
     * A blank username must never reach the database silently — without this
     * guard an admin/edit submission with an empty name field would create or
     * overwrite a user with no identifiable name.
     */
    public function testEditMissingNameIsRejected(): void
    {
        $this->mockRequestFields(['name' => null]);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('An username is needed');

        $form->validateFor(AclActionsInterface::USER_EDIT, 10);
    }

    /**
     * A blank login must be rejected: it is the credential used to authenticate,
     * so a silently-accepted blank would leave the account unreachable/unsafe.
     */
    public function testEditMissingLoginIsRejected(): void
    {
        $this->mockRequestFields(['login' => null]);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A login is needed');

        $form->validateFor(AclActionsInterface::USER_EDIT, 10);
    }

    /**
     * A user without a profile has no permission set at all; the form must
     * refuse to save one rather than let it fall through to a null profile.
     */
    public function testEditMissingProfileIsRejected(): void
    {
        $this->mockRequestFields(['userprofile_id' => null]);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A profile is needed');

        $form->validateFor(AclActionsInterface::USER_EDIT, 10);
    }

    /**
     * A user without a group cannot be granted any account access; the form
     * must refuse rather than persist a groupless user.
     */
    public function testEditMissingGroupIsRejected(): void
    {
        $this->mockRequestFields(['usergroup_id' => null]);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A group is needed');

        $form->validateFor(AclActionsInterface::USER_EDIT, 10);
    }

    /**
     * A blank email must be rejected for non-LDAP users: it is used for
     * notifications and password-reset flows, so silently accepting a blank
     * one would leave those flows unreachable for the account.
     */
    public function testEditMissingEmailIsRejected(): void
    {
        $this->mockRequestFields(['email' => null]);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('An email is needed');

        $form->validateFor(AclActionsInterface::USER_EDIT, 10);
    }

    /**
     * LDAP-provisioned users have no local name/login/email to submit (the
     * directory owns those); the form must not force the name/login/email
     * guards on them, or every LDAP user edit would be rejected outright.
     */
    public function testLdapUserSkipsNameLoginEmailChecks(): void
    {
        $this->mockRequestFields(['isLdap' => 1, 'name' => null, 'login' => null, 'email' => null]);

        $form = $this->buildForm();
        $result = $form->validateFor(AclActionsInterface::USER_EDIT, 10);

        $this->assertSame($form, $result);
        $this->assertTrue($form->getItemData()->isLdap());
        $this->assertSame(1, $form->getIsLdap());
    }

    /**
     * checkCommon() has its own isDemo() guard, independent from checkPass()'s
     * and checkDelete()'s: the demo admin account must be locked down against
     * a plain edit too, even when every other field submitted is valid.
     */
    public function testEditCommonDemoAdminIsRejected(): void
    {
        $this->mockRequestFields();
        $form = new UserForm($this->buildDemoAdminApplication(), $this->request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Ey, this is a DEMO!!');

        $form->validateFor(AclActionsInterface::USER_EDIT, User::DEMO_ADMIN_ID);
    }

    /**
     * A blank new password (or a blank repeat) must never be accepted as a
     * "successful" password change — that would leave the stored hash
     * unrelated to what the operator believes they just set.
     */
    public function testEditPassBlankPasswordIsRejected(): void
    {
        $this->mockRequestFields();

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Password cannot be blank');

        $form->validateFor(AclActionsInterface::USER_EDIT_PASS, 10);
    }

    /**
     * A mismatched password/repeat pair must be rejected — without this check
     * a typo in the repeat field would silently set the password to a value
     * the operator never actually confirmed.
     */
    public function testEditPassMismatchIsRejected(): void
    {
        $this->mockRequestFields(['password' => 'secretA', 'password_repeat' => 'secretB']);

        $form = $this->buildForm();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Passwords do not match');

        $form->validateFor(AclActionsInterface::USER_EDIT_PASS, 10);
    }

    /**
     * The positive control for checkPass(): matching, non-blank passwords must
     * actually succeed, proving the guards above reject only the bad cases and
     * do not block a legitimate password change.
     */
    public function testEditPassMatchingPasswordsSucceed(): void
    {
        $this->mockRequestFields(['password' => 'sameSecret', 'password_repeat' => 'sameSecret']);

        $form = $this->buildForm();
        $result = $form->validateFor(AclActionsInterface::USER_EDIT_PASS, 10);

        $this->assertSame($form, $result);
        $this->assertSame('sameSecret', $form->getItemData()->getPass());
    }

    /**
     * checkPass()'s isDemo() guard fires before the blank/mismatch checks —
     * the demo admin's password must stay locked even when a well-formed,
     * matching password pair is submitted.
     */
    public function testEditPassDemoAdminIsRejected(): void
    {
        $this->mockRequestFields(['password' => 'sameSecret', 'password_repeat' => 'sameSecret']);
        $form = new UserForm($this->buildDemoAdminApplication(), $this->request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Ey, this is a DEMO!!');

        $form->validateFor(AclActionsInterface::USER_EDIT_PASS, User::DEMO_ADMIN_ID);
    }

    /**
     * checkDelete()'s isDemo() guard is checked before the self-delete guard:
     * the demo admin account must be undeletable even by another app-admin
     * actor (i.e. it cannot be bypassed just because the actor isn't deleting
     * themselves).
     */
    public function testDeleteDemoAdminIsRejected(): void
    {
        $form = new UserForm($this->buildDemoAdminApplication(), $this->request);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Ey, this is a DEMO!!');

        $form->validateFor(AclActionsInterface::USER_DELETE, User::DEMO_ADMIN_ID);
    }

    /**
     * USER_DELETE never populates $userData (only checkDelete() runs, which
     * never touches it); getItemData() must fail loudly instead of returning
     * null/stale data to a caller that expects a User after validateFor().
     */
    public function testGetItemDataThrowsWhenUserDataNotSet(): void
    {
        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::USER_DELETE, $this->context->getUserData()->id + 1);

        $this->expectException(SPException::class);
        $this->expectExceptionMessage('User data not set');

        $form->getItemData();
    }

    // ── helpers ──────────────────────────────────────────────────────────────


    private function buildForm(?int $itemId = null): UserForm
    {
        return new UserForm($this->application, $this->request, $itemId);
    }

    /**
     * Builds an Application whose config has the demo mode enabled and whose
     * actor is an app-admin — the two preconditions UserForm::isDemo() needs
     * besides the target itemId matching User::DEMO_ADMIN_ID.
     */
    private function buildDemoAdminApplication(): Application
    {
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => true])
        );

        $configData = new ConfigData([ConfigDataInterface::DEMO_ENABLED => true]);
        $config = $this->createStub(ConfigFileService::class);
        $config->method('getConfigData')->willReturn($configData);

        return new Application(
            $config,
            $this->createStub(EventDispatcherInterface::class),
            $this->context
        );
    }

    /**
     * Configures $this->request with valid defaults for every field UserForm
     * reads, so a test can override just the field(s) it cares about and still
     * get a submission that would otherwise pass every other guard.
     */
    private function mockRequestFields(array $overrides = []): void
    {
        $values = array_merge(
            [
                'isLdap' => 0,
                'name' => 'Test User',
                'login' => 'testuser',
                'usergroup_id' => 1,
                'userprofile_id' => 2,
                'email' => 'test@example.com',
                'password' => null,
                'password_repeat' => null,
            ],
            $overrides
        );

        $this->request->method('analyzeInt')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'isLdap' => $values['isLdap'],
                'usergroup_id' => $values['usergroup_id'],
                'userprofile_id' => $values['userprofile_id'],
                default => null,
            }
        );

        $this->request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'name' => $values['name'],
                'login' => $values['login'],
                default => null,
            }
        );

        $this->request->method('analyzeEmail')->willReturn($values['email']);
        $this->request->method('analyzeUnsafeString')->willReturn(null);
        $this->request->method('analyzeBool')->willReturn(false);
        $this->request->method('analyzeEncrypted')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'password' => $values['password'],
                'password_repeat' => $values['password_repeat'],
                default => null,
            }
        );
    }

    private function setActorAsAdmin(): void
    {
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => true])
        );
    }

    private function mockRequestForUserEdit(bool $adminAppEnabled, bool $adminAccEnabled): void
    {
        $this->request->method('analyzeInt')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'isLdap'         => 0,
                'usergroup_id'   => 1,
                'userprofile_id' => 2,
                default          => null,
            }
        );

        $this->request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => match ($param) {
                'name'      => 'Test User',
                'login'     => 'testuser',
                default     => null,
            }
        );

        $this->request->method('analyzeEmail')->willReturn('test@example.com');
        $this->request->method('analyzeUnsafeString')->willReturn(null);
        $this->request->method('analyzeEncrypted')->willReturn(null);

        $this->request->method('analyzeBool')->willReturnCallback(
            static fn(string $param, bool $default = false) => match ($param) {
                'adminapp_enabled'   => $adminAppEnabled,
                'adminacc_enabled'   => $adminAccEnabled,
                default              => false,
            }
        );
    }
}
