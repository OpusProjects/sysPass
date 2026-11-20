<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\User;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Adapter\In\Api\Controllers\User\EditController;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class EditControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserService $userService;
    private MockObject|UserProfileService $userProfileService;
    private EditController $controller;

    /**
     * A non-app-admin actor submitting isAdminApp=1 must have that flag forced to
     * false in the persisted User — the old test asserted the flag was true, encoding
     * the privilege-escalation vulnerability.
     */
    public function testEditActionNonAdminCannotGrantAdminApp(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_EDIT);

        $paramStringMap = [
            ['name', true, null, 'Updated User'],
            ['login', true, null, 'updateduser'],
            ['email', false, null, 'updated@example.com'],
            ['notes', false, null, 'Updated notes'],
        ];

        $paramIntMap = [
            ['id', true, null, 3],
            ['userGroupId', true, null, 1],
            ['userProfileId', true, null, 2],
            ['isAdminApp', false, null, 1],  // non-admin requests the flag …
            ['isAdminAcc', false, null, 1],  // … and this one too
            ['isDisabled', false, null, 0],
            ['isChangePass', false, null, 0],
        ];

        $this->apiService->method('getParamString')->willReturnMap($paramStringMap);
        $this->apiService->method('getParamInt')->willReturnMap($paramIntMap);

        // Default context user is non-admin (isAdminApp = null / false).
        // Both flags must be forced false regardless of request.
        $this->userService
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (User $user) {
                return $user->getId() === 3
                    && $user->getName() === 'Updated User'
                    && $user->getLogin() === 'updateduser'
                    && $user->isAdminApp() === false
                    && $user->isAdminAcc() === false;
            }));

        $response = $this->controller->editAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(3, $result['itemId']);
        $this->assertEquals('User updated', $result['resultMessage']);
    }

    /**
     * An app-admin actor requesting isAdminApp=1 must have the flag honoured.
     */
    public function testEditActionAdminCanGrantAdminApp(): void
    {
        // Promote the actor to app-admin.
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => true])
        );

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_EDIT);

        $paramStringMap = [
            ['name', true, null, 'Updated User'],
            ['login', true, null, 'updateduser'],
            ['email', false, null, 'updated@example.com'],
            ['notes', false, null, null],
        ];

        $paramIntMap = [
            ['id', true, null, 3],
            ['userGroupId', true, null, 1],
            ['userProfileId', true, null, 2],
            ['isAdminApp', false, null, 1],
            ['isAdminAcc', false, null, 0],
            ['isDisabled', false, null, 0],
            ['isChangePass', false, null, 0],
        ];

        $this->apiService->method('getParamString')->willReturnMap($paramStringMap);
        $this->apiService->method('getParamInt')->willReturnMap($paramIntMap);

        $this->userService
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (User $user) {
                return $user->getId() === 3
                    && $user->isAdminApp() === true
                    && $user->isAdminAcc() === false;
            }));

        $response = $this->controller->editAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
    }

    /**
     * A flag the caller does not send keeps its stored value. It used to be written as false, so
     * an administrator renaming another administrator demoted them, and re-enabled them if they
     * had been disabled, without either flag appearing in the request.
     */
    public function testEditActionKeepsTheFlagsItIsNotSent(): void
    {
        $this->context->setUserData($this->context->getUserData()->mutate(['isAdminApp' => true]));

        $this->apiService->method('getParamString')->willReturnMap([
            ['name', true, null, 'Renamed'],
            ['login', true, null, 'renamed'],
        ]);
        // Only what a rename sends: no isAdminApp, isAdminAcc, isDisabled, isChangePass or
        // isTotpRequired — the last of which an administrator set to make the user enrol.
        $this->apiService->method('getParamInt')->willReturnCallback(
            static fn(string $param): ?int => ['id' => 3, 'userGroupId' => 1, 'userProfileId' => 2][$param] ?? null
        );

        $this->userService
            ->expects($this->once())
            ->method('getById')
            ->with(3)
            ->willReturn(new User([
                'id' => 3,
                'isAdminApp' => true,
                'isAdminAcc' => true,
                'isDisabled' => true,
                'isChangePass' => true,
                'isTotpRequired' => true,
            ]));

        $this->userService
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(static fn(User $user): bool => $user->isAdminApp() === true
                && $user->isAdminAcc() === true
                && $user->isDisabled() === true
                && $user->isChangePass() === true
                && $user->isTotpRequired() === true));

        $this->controller->editAction();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->userService = $this->createMock(UserService::class);
        $this->userProfileService = $this->createStub(UserProfileService::class);

        $router = new Router(
            new SymfonyRequest(),
            $this->createStub(ResponseService::class)
        );

        $this->context->setTrasientKey('_actionName', 'edit');

        $this->controller = new EditController(
            $this->application,
            $router,
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->userService,
            $this->userProfileService
        );
    }
}
