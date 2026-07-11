<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\AuthToken;

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
use SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken\DeleteController;
use SP\Infrastructure\Adapter\Out\Common\Repositories\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class DeleteControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|AuthTokenService $authTokenService;
    private DeleteController $controller;

    public function testDeleteAction(): void
    {
        $token = new AuthToken(['id' => 1, 'userId' => 1, 'actionId' => 100]);

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::AUTHTOKEN_DELETE);
        $this->apiService->expects($this->once())->method('getParamInt')->with('id', true)->willReturn(1);
        $this->authTokenService->expects($this->once())->method('getById')->with(1)->willReturn($token);
        $this->authTokenService->expects($this->once())->method('delete')->with(1);

        $response = $this->controller->deleteAction();
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(1, $result['itemId']);
        $this->assertEquals('Authorization removed', $result['resultMessage']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiService = $this->createMock(ApiService::class);
        $this->authTokenService = $this->createMock(AuthTokenService::class);
        $this->context->setTrasientKey('_actionName', 'delete');
        $this->controller = new DeleteController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService, $this->createStub(AclInterface::class), $this->authTokenService
        );
    }
}
