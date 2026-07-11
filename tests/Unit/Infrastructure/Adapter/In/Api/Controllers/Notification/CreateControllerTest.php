<?php

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\Notification;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Notification\Ports\NotificationService;
use SP\Core\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\Notification\CreateController;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CreateControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|NotificationService $notificationService;
    private CreateController $controller;

    public function testCreateAction(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_CREATE);
        $this->apiService->method('getParamString')->willReturnMap([
            ['type', true, null, 'info'],
            ['component', true, null, 'Test'],
            ['description', true, null, 'Test notification'],
        ]);
        $this->apiService->method('getParamInt')->willReturnMap([
            ['userId', false, null, 7],
            ['sticky', false, null, 0],
            ['onlyAdmin', false, null, 0],
        ]);
        $this->notificationService->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(NotificationModel::class))
            ->willReturn(10);

        $response = $this->controller->createAction();
        $result = $response->getResponse();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(10, $result['itemId']);
        $this->assertEquals('Notification added', $result['resultMessage']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->context->setTrasientKey('_actionName', 'create');
        $this->controller = new CreateController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->notificationService
        );
    }
}
