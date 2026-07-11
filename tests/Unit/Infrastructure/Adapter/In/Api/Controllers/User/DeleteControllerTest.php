<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\User;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Adapter\In\Api\Controllers\User\DeleteController;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DeleteControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserService $userService;
    private DeleteController $controller;

    public function testDeleteAction(): void
    {
        $user = UserDataGenerator::factory()->buildUserData();

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_DELETE);

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('id', true)
            ->willReturn(1);

        $this->userService
            ->expects($this->once())
            ->method('getById')
            ->with(1)
            ->willReturn($user);

        $this->userService
            ->expects($this->once())
            ->method('delete')
            ->with(1);

        $response = $this->controller->deleteAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(1, $result['itemId']);
        $this->assertEquals('User removed', $result['resultMessage']);
    }

    public function testDeleteActionNotFound(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_DELETE);

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('id', true)
            ->willReturn(999);

        $this->userService
            ->expects($this->once())
            ->method('getById')
            ->with(999)
            ->willThrowException(new NoSuchItemException('User does not exist'));

        $this->expectException(NoSuchItemException::class);
        $this->expectExceptionMessage('User does not exist');

        $this->controller->deleteAction();
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

        $this->context->setTrasientKey('_actionName', 'delete');

        $this->controller = new DeleteController(
            $this->application,
            $router,
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->userService
        );
    }
}
