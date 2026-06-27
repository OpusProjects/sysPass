<?php
declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Core\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Auth\Models\AuthToken;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Http\Ports\ResponseService;
use SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken\CreateController;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CreateControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|AuthTokenService $authTokenService;
    private CreateController $controller;

    public function testCreateAction(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::AUTHTOKEN_CREATE);
        $this->apiService->method('getParamInt')->willReturnMap([
            ['userId', true, null, 1],
            ['actionId', true, null, 702],
        ]);
        $this->apiService->method('getParamString')->willReturnMap([
            ['password', false, null, 'secret'],
        ]);
        $this->authTokenService->expects($this->once())->method('create')->with($this->isInstanceOf(AuthToken::class))->willReturn(5);

        $response = $this->controller->createAction();
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(5, $result['itemId']);
        $this->assertEquals('Authorization added', $result['resultMessage']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->authTokenService = $this->createMock(AuthTokenService::class);
        $this->context->setTrasientKey('_actionName', 'create');
        $this->controller = new CreateController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService, $this->createStub(AclInterface::class), $this->authTokenService
        );
    }
}
