<?php
declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers\User;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserService;
use SP\Core\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Adapter\In\Api\Controllers\User\CreateController;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CreateControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserService $userService;
    private CreateController $controller;

    public function testCreateAction(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_CREATE);

        $paramStringMap = [
            ['name', true, null, 'Test User'],
            ['login', true, null, 'testuser'],
            ['email', false, null, 'test@example.com'],
            ['notes', false, null, 'Test notes'],
        ];

        $paramIntMap = [
            ['userGroupId', true, null, 1],
            ['userProfileId', true, null, 2],
            ['isAdminApp', false, null, 0],
            ['isAdminAcc', false, null, 0],
            ['isDisabled', false, null, 0],
            ['isChangePass', false, null, 0],
        ];

        $this->apiService
            ->method('getParamString')
            ->willReturnMap($paramStringMap);

        $this->apiService
            ->method('getParamInt')
            ->willReturnMap($paramIntMap);

        $this->userService
            ->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(User::class))
            ->willReturn(5);

        $response = $this->controller->createAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(5, $result['itemId']);
        $this->assertEquals('User added', $result['resultMessage']);
    }

    /**
     * A non-app-admin actor submitting isAdminApp=1 must have that flag forced to
     * false in the persisted User.
     */
    public function testCreateActionNonAdminCannotGrantAdminApp(): void
    {
        $this->apiService->method('setup');

        $this->apiService->method('getParamString')->willReturnMap([
            ['name', true, null, 'Test User'],
            ['login', true, null, 'testuser'],
            ['email', false, null, 'test@example.com'],
            ['notes', false, null, null],
        ]);

        // Non-admin actor; both flags requested as 1.
        $this->apiService->method('getParamInt')->willReturnMap([
            ['userGroupId', true, null, 1],
            ['userProfileId', true, null, 2],
            ['isAdminApp', false, null, 1],
            ['isAdminAcc', false, null, 1],
            ['isDisabled', false, null, 0],
            ['isChangePass', false, null, 0],
        ]);

        $capturedUser = null;
        $this->userService
            ->method('create')
            ->willReturnCallback(function (User $user) use (&$capturedUser) {
                $capturedUser = $user;
                return 99;
            });

        $this->controller->createAction();

        $this->assertNotNull($capturedUser);
        $this->assertFalse($capturedUser->isAdminApp(), 'non-admin must not grant isAdminApp');
        $this->assertFalse($capturedUser->isAdminAcc(), 'non-admin must not grant isAdminAcc');
    }

    /**
     * An app-admin actor requesting isAdminApp=1 must have the flag honoured.
     */
    public function testCreateActionAdminCanGrantAdminApp(): void
    {
        // Promote the actor to app-admin.
        $this->context->setUserData(
            $this->context->getUserData()->mutate(['isAdminApp' => true])
        );

        $this->apiService->method('setup');

        $this->apiService->method('getParamString')->willReturnMap([
            ['name', true, null, 'Test User'],
            ['login', true, null, 'testuser'],
            ['email', false, null, 'test@example.com'],
            ['notes', false, null, null],
        ]);

        $this->apiService->method('getParamInt')->willReturnMap([
            ['userGroupId', true, null, 1],
            ['userProfileId', true, null, 2],
            ['isAdminApp', false, null, 1],
            ['isAdminAcc', false, null, 1],
            ['isDisabled', false, null, 0],
            ['isChangePass', false, null, 0],
        ]);

        $capturedUser = null;
        $this->userService
            ->method('create')
            ->willReturnCallback(function (User $user) use (&$capturedUser) {
                $capturedUser = $user;
                return 99;
            });

        $this->controller->createAction();

        $this->assertNotNull($capturedUser);
        $this->assertTrue($capturedUser->isAdminApp(), 'admin must be able to grant isAdminApp');
        $this->assertTrue($capturedUser->isAdminAcc(), 'admin must be able to grant isAdminAcc');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->userService = $this->createMock(UserService::class);

        $router = new Router(
            new SymfonyRequest(),
            $this->createStub(ResponseService::class)
        );

        $this->context->setTrasientKey('_actionName', 'create');

        $this->controller = new CreateController(
            $this->application,
            $router,
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->userService
        );
    }
}
