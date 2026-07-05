<?php

declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers\Notification;

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
use SP\Infrastructure\Adapter\In\Api\Controllers\Notification\CheckController;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CheckControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|NotificationService $notificationService;
    private CheckController $controller;

    public function testCheckAction(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_CHECK);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(42);
        $this->notificationService->expects($this->once())->method('setCheckedById')->with(42);

        $response = $this->controller->checkAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertEquals(0, $response->getResponse()['resultCode']);
        $this->assertEquals(42, $response->getResponse()['itemId']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->context->setTrasientKey('_actionName', 'check');
        $this->controller = new CheckController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->notificationService
        );
    }
}
