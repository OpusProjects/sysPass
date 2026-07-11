<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\Auth\Ports\AuthTokenService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Auth\Models\AuthToken;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken\ViewController;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ViewControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|AuthTokenService $authTokenService;
    private ViewController $controller;

    public function testViewAction(): void
    {
        $token = new AuthToken(['id' => 1, 'userId' => 1, 'actionId' => 100]);

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::AUTHTOKEN_VIEW);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(1);
        $this->authTokenService->expects($this->once())->method('getById')->with(1)->willReturn($token);

        $response = $this->controller->viewAction();
        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertSame($token, $response->getResponse()['result']);
    }

    public function testViewActionNotFound(): void
    {
        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::AUTHTOKEN_VIEW);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(999);
        $this->authTokenService->expects($this->once())->method('getById')->with(999)->willThrowException(new NoSuchItemException('Authorization not found'));
        $this->expectException(NoSuchItemException::class);
        $this->controller->viewAction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->authTokenService = $this->createMock(AuthTokenService::class);
        $this->context->setTrasientKey('_actionName', 'view');
        $this->controller = new ViewController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService, $this->createStub(AclInterface::class), $this->authTokenService
        );
    }
}
