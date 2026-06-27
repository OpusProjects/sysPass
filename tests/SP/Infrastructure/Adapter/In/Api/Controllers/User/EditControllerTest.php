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
use SP\Infrastructure\Adapter\In\Api\Controllers\User\EditController;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class EditControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserService $userService;
    private EditController $controller;

    public function testEditAction(): void
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
            ['isAdminApp', false, null, 1],
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
            ->method('update')
            ->with($this->callback(function (User $user) {
                return $user->getId() === 3
                    && $user->getName() === 'Updated User'
                    && $user->getLogin() === 'updateduser'
                    && $user->isAdminApp() === true;
            }));

        $response = $this->controller->editAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(3, $result['itemId']);
        $this->assertEquals('User updated', $result['resultMessage']);
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

        $this->context->setTrasientKey('_actionName', 'edit');

        $this->controller = new EditController(
            $this->application,
            $router,
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->userService
        );
    }
}
