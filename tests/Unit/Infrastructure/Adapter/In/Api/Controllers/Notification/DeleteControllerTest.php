<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\Notification;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\Notification\DeleteController;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DeleteControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|NotificationService $notificationService;
    private DeleteController $controller;

    public function testDeleteAction(): void
    {
        $notification = new NotificationModel(['id' => 5, 'userId' => 1]);

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_DELETE);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(5);
        $this->notificationService->expects($this->once())->method('getById')->with(5)->willReturn($notification);
        $this->notificationService->expects($this->once())->method('delete')->with(5);

        $response = $this->controller->deleteAction();
        $result = $response->getResponse();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(5, $result['itemId']);
        $this->assertSame($notification, $result['result']);
    }

    public function testDeleteActionNotFound(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_DELETE);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(999);
        $this->notificationService->expects($this->once())->method('getById')->with(999)
            ->willThrowException(new NoSuchItemException('Notification not found'));

        $this->expectException(NoSuchItemException::class);
        $this->controller->deleteAction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->context->setTrasientKey('_actionName', 'delete');
        $this->controller = new DeleteController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->notificationService
        );
    }
}
