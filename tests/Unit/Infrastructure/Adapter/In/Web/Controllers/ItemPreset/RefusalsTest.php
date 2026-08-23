<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ItemPreset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\ItemPreset\Ports\ItemPresetService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\ItemPresetHelper;
use SP\Infrastructure\Adapter\In\Web\Controllers\ItemPreset\DeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ItemPreset\EditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ItemPreset\SaveCreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\ItemPreset\ViewController;
use SP\Application\User\Ports\UserGroupService;
use SP\Application\User\Ports\UserProfileService;
use SP\Application\User\Ports\UserService;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Application\Application;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * The refusal is the first thing each action checks and the last thing anything exercises: the
 * integration harness binds an ACL that answers `true` to everything, so a request dispatched
 * through it cannot be denied. These reach the branch by mocking the ACL closed, and assert the
 * other half too — that the service behind the controller is never asked for anything, so a refusal
 * is a refusal rather than a denial message printed after the work was done.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function viewIsRefusedWhenTheAclDenies(): void
    {
        $itemPresetService = $this->createMock(ItemPresetService::class);
        $itemPresetService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $controller = new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'view'),
            $itemPresetService,
            $this->itemPresetHelper($application)
        );

        $response = $controller->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editIsRefusedWhenTheAclDenies(): void
    {
        $itemPresetService = $this->createMock(ItemPresetService::class);
        $itemPresetService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $controller = new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'edit'),
            $itemPresetService,
            $this->itemPresetHelper($application)
        );

        $response = $controller->editAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $itemPresetService = $this->createMock(ItemPresetService::class);
        $itemPresetService->expects(self::never())->method('delete');
        $itemPresetService->expects(self::never())->method('deleteByIdBatch');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'delete'),
            $itemPresetService
        ))->deleteAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingANewPresetIsRefusedWhenTheAclDenies(): void
    {
        $itemPresetService = $this->createMock(ItemPresetService::class);
        $itemPresetService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'saveCreate'),
            $itemPresetService
        ))->saveCreateAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * A real one: the helper is `final`, so it cannot be doubled, and its own collaborators are all
     * interfaces. It is never used in these tests — the refusal happens before the controller
     * reaches it — but it has to exist for the controller to construct.
     *
     * @throws Exception
     */
    private function itemPresetHelper(Application $application): ItemPresetHelper
    {
        return new ItemPresetHelper(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class),
            $this->createStub(UserService::class),
            $this->createStub(UserGroupService::class),
            $this->createStub(UserProfileService::class)
        );
    }
}
