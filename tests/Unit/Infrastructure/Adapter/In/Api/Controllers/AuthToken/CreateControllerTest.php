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
use SP\Infrastructure\Adapter\In\Api\Controllers\AuthToken\CreateController;
use SP\Tests\Support\UnitaryTestCase;
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
        $this->apiService->method('getParamRaw')->willReturnMap([
            ['password', false, null, 'secret'],
        ]);
        $this->authTokenService->expects($this->once())->method('create')->with($this->isInstanceOf(AuthToken::class))->willReturn(5);

        $response = $this->controller->createAction();
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(5, $result['itemId']);
        $this->assertEquals('Authorization added', $result['resultMessage']);
    }

    /**
     * The password must be read via getParamRaw (not getParamString), so that
     * special characters (&, <, >) and edge whitespace survive untouched into the
     * hash seed — getParamString trims and HTML-encodes the value, which would make
     * the hash computed at creation time never match the raw value checked at use
     * time (SP\Application\Api\Services\Api::getMasterPassFromVault).
     */
    public function testCreateActionWithSpecialCharacterPasswordIsReadRaw(): void
    {
        $rawPassword = ' <p&ss "word"> ';

        $this->apiService->expects($this->once())->method('setup')->with(AclActionsInterface::AUTHTOKEN_CREATE);
        $this->apiService->method('getParamInt')->willReturnMap([
            ['userId', true, null, 1],
            ['actionId', true, null, 702],
        ]);
        $this->apiService->expects($this->once())
            ->method('getParamRaw')
            ->with('password')
            ->willReturn($rawPassword);
        $this->apiService->expects($this->never())->method('getParamString');

        $this->authTokenService->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(AuthToken $authToken) => $authToken->getHash() === $rawPassword))
            ->willReturn(5);

        $this->controller->createAction();
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
