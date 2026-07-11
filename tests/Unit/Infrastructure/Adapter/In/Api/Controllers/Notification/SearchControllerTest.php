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
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Domain\Notification\Models\Notification as NotificationModel;
use SP\Infrastructure\Adapter\In\Api\Controllers\Notification\SearchController;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class SearchControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|NotificationService $notificationService;
    private SearchController $controller;

    public function testSearchAction(): void
    {
        $notification = new NotificationModel(['id' => 1, 'userId' => 1]);
        $queryResult = new QueryResult([$notification]);

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::NOTIFICATION_SEARCH);
        $this->apiService->expects($this->once())->method('getParamString')->with('text')->willReturn(null);
        $this->apiService->expects($this->once())->method('getParamInt')->with('count', false, 25)->willReturn(10);
        $this->notificationService->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(ItemSearchDto::class))
            ->willReturn($queryResult);

        $response = $this->controller->searchAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertEquals(0, $response->getResponse()['resultCode']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->context->setTrasientKey('_actionName', 'search');
        $this->controller = new SearchController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->notificationService
        );
    }
}
