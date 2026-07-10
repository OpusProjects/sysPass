<?php
declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Adapter\In\Web\Forms\UserForm;
use SP\Tests\UnitaryTestCase;

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

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildForm(?int $itemId = null): UserForm
    {
        return new UserForm($this->application, $this->request, $itemId);
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
