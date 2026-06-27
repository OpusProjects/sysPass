<?php
declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Api\Controllers\Profile;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Api\Ports\ApiService;
use SP\Application\User\Ports\UserProfileService;
use SP\Core\Bootstrap\Router;
use SP\Domain\Api\Dtos\ApiResponse;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Dtos\ItemSearchDto;
use SP\Domain\Http\Ports\ResponseService;
use SP\Domain\User\Models\UserProfile;
use SP\Infrastructure\Adapter\In\Api\Controllers\Profile\SearchController;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\UnitaryTestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class SearchControllerTest extends UnitaryTestCase
{
    private MockObject|ApiService $apiService;
    private MockObject|UserProfileService $profileService;
    private SearchController $controller;

    public function testSearchAction(): void
    {
        $profile = new UserProfile(['id' => 1, 'name' => 'Admin']);
        $queryResult = new QueryResult([$profile]);

        $this->apiService
            ->expects($this->once())
            ->method('setup')
            ->with(AclActionsInterface::PROFILE_SEARCH);

        $this->apiService
            ->expects($this->once())
            ->method('getParamString')
            ->with('text')
            ->willReturn('admin');

        $this->apiService
            ->expects($this->once())
            ->method('getParamInt')
            ->with('count', false, 25)
            ->willReturn(10);

        $this->profileService
            ->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(ItemSearchDto::class))
            ->willReturn($queryResult);

        $response = $this->controller->searchAction();

        $this->assertInstanceOf(ApiResponse::class, $response);
        $result = $response->getResponse();
        $this->assertEquals(0, $result['resultCode']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiService = $this->createMock(ApiService::class);
        $this->profileService = $this->createMock(UserProfileService::class);

        $this->context->setTrasientKey('_actionName', 'search');

        $this->controller = new SearchController(
            $this->application,
            new Router(new SymfonyRequest(), $this->createStub(ResponseService::class)),
            $this->apiService,
            $this->createStub(AclInterface::class),
            $this->profileService
        );
    }
}
