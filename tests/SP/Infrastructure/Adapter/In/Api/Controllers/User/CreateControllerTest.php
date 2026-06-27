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
