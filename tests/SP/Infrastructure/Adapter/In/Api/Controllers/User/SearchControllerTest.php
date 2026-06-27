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
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Http\Ports\ResponseService;
use SP\Infrastructure\Adapter\In\Api\Controllers\User\SearchController;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Generators\UserDataGenerator;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class SearchControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserService $userService;
    private SearchController $controller;

    public function testSearchAction(): void
    {
        $user = UserDataGenerator::factory()->buildUserData();
        $queryResult = new QueryResult([$user]);

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_SEARCH);

        $this->apiService
            ->expects($this->once())
            ->method('getParamString')
            ->with('text')
            ->willReturn('test');

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('count', false, 25)
            ->willReturn(10);

        $this->userService
            ->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(ItemSearchDto::class))
            ->willReturn($queryResult);

        $response = $this->controller->searchAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
    }

    public function testSearchActionWithDefaults(): void
    {
        $queryResult = new QueryResult([]);

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::USER_SEARCH);

        $this->apiService
            ->method('getParamString')
            ->willReturn(null);

        $this->apiService
            ->method('getParamInt')
            ->willReturn(null);

        $this->userService
            ->expects($this->once())
            ->method('search')
            ->willReturn($queryResult);

        $response = $this->controller->searchAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
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

        $this->context->setTrasientKey('_actionName', 'search');

        $this->controller = new SearchController(
            $this->application,
            $router,
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->userService
        );
    }
}
