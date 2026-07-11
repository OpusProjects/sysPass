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
use SP\Infrastructure\Adapter\In\Api\Controllers\Notification\ViewController;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ViewControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|NotificationService $notificationService;
    private ViewController $controller;

    public function testViewAction(): void
    {
        $notification = new NotificationModel(['id' => 1, 'userId' => 1]);

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_VIEW);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(1);
        $this->notificationService->expects($this->once())->method('getById')->with(1)->willReturn($notification);

        $response = $this->controller->viewAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame($notification, $response->getResponse()['result']);
    }

    public function testViewActionNotFound(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_VIEW);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(999);
        $this->notificationService->expects($this->once())->method('getById')->with(999)
            ->willThrowException(new NoSuchItemException('Notification not found'));

        $this->expectException(NoSuchItemException::class);
        $this->controller->viewAction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->context->setTrasientKey('_actionName', 'view');
        $this->controller = new ViewController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->notificationService
        );
    }
}
