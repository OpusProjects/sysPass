<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Api\Controllers\Profile;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Infrastructure\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Infrastructure\Http\Ports\ResponseService;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Adapter\In\Api\Controllers\Profile\EditController;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class EditControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserProfileService $profileService;
    private EditController $controller;

    public function testEditAction(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::PROFILE_EDIT);

        $this->apiService
            ->method('getParamString')
            ->willReturnMap([
                ['name', true, null, 'Updated Profile'],
                ['profile', false, null, null],
            ]);

        $this->apiService
            ->method('getParamInt')
            ->willReturnMap([
                ['id', true, null, 3],
            ]);

        $this->profileService
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (UserProfile $p) {
                return $p->getId() === 3 && $p->getName() === 'Updated Profile';
            }));

        $response = $this->controller->editAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertEquals(3, $result['itemId']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->profileService = $this->createMock(UserProfileService::class);

        $this->context->setTrasientKey('_actionName', 'edit');

        $this->controller = new EditController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->profileService
        );
    }
}
