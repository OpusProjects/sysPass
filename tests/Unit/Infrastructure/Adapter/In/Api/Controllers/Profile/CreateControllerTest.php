<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\Profile;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Core\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Adapter\In\Api\Controllers\Profile\CreateController;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class CreateControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserProfileService $profileService;
    private CreateController $controller;

    public function testCreateAction(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::PROFILE_CREATE);

        $this->apiService
            ->method('getParamString')
            ->willReturnMap([
                ['name', true, null, 'Test Profile'],
                ['profile', false, null, null],
            ]);

        $this->profileService
            ->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(UserProfile::class))
            ->willReturn(5);

        $response = $this->controller->createAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(5, $result['itemId']);
        $this->assertEquals('Profile added', $result['resultMessage']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->profileService = $this->createMock(UserProfileService::class);

        $this->context->setTrasientKey('_actionName', 'create');

        $this->controller = new CreateController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->profileService
        );
    }
}
