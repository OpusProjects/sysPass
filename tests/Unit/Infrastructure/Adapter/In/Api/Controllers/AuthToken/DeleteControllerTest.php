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
use SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken\DeleteController;
use SP\Domain\Core\Exceptions\NoSuchItemException;
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

    /**
     * The deleted row comes back without its token, hash or vault. A user's tokens share one
     * token string, so the one on a deleted row still opens the others.
     */
    public function testDeleteActionReturnsNoSecrets(): void
    {
        $token = new AuthToken(
            ['id' => 1, 'userId' => 1, 'actionId' => 100, 'token' => 'live-token', 'hash' => 'h', 'vault' => 'v']
        );

        $this->apiService->method('getParamInt')->willReturn(1);
        $this->authTokenService->method('getById')->willReturn($token);

        $result = $this->controller->deleteAction()->getResponse()['result'];

        $this->assertSame(1, $result['id']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertArrayNotHasKey('hash', $result);
        $this->assertArrayNotHasKey('vault', $result);
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
