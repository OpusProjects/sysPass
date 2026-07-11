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
use SP\Infrastructure\Adapter\In\Api\Controllers\Profile\ViewController;
use SP\Domain\Core\Exceptions\NoSuchItemException;
use SP\Tests\Support\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class ViewControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserProfileService $profileService;
    private ViewController $controller;

    public function testViewAction(): void
    {
        $profile = new UserProfile(['id' => 1, 'name' => 'Admin']);

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::PROFILE_VIEW);

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('id', true)
            ->willReturn(1);

        $this->profileService
            ->expects($this->once())
            ->method('getById')
            ->with(1)
            ->willReturn($profile);

        $response = $this->controller->viewAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
        $this->assertSame($profile, $result['result']);
    }

    public function testViewActionNotFound(): void
    {
        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::PROFILE_VIEW);

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('id', true)
            ->willReturn(999);

        $this->profileService
            ->expects($this->once())
            ->method('getById')
            ->with(999)
            ->willThrowException(new NoSuchItemException('Profile does not exist'));

        $this->expectException(NoSuchItemException::class);

        $this->controller->viewAction();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->profileService = $this->createMock(UserProfileService::class);

        $this->context->setTrasientKey('_actionName', 'view');

        $this->controller = new ViewController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->profileService
        );
    }
}
