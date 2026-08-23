<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\UserGroup;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\CustomField\Ports\CustomFieldDataService;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserService;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\UserGroup\ViewController;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so the
 * refusal branch of every action goes unexercised there. These mock it closed and assert both that
 * the refusal reaches the caller and that the service behind the controller is never asked for
 * anything.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(UserGroupService::class);
        $service->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'create'),
            $service,
            $this->createStub(UserService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(UserGroupService::class);
        $service->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'userGroup', 'view'),
            $service,
            $this->createStub(UserService::class),
            $this->createStub(CustomFieldDataService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
