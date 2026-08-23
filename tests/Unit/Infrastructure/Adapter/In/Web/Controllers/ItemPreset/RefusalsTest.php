<?php

/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\ItemPreset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
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
     * What the action does when the work behind it fails.
     *
     * Viewing a preset reads it by id before the template is rendered; when that read throws,
     * the caller must be told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function viewReportsAFailureBehindItRatherThanEscaping(): void
    {
        $itemPresetService = $this->createStub(ItemPresetService::class);
        $itemPresetService->method('getById')
                           ->willThrowException(new RuntimeException('the preset could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $controller = new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'view'),
            $itemPresetService,
            $this->itemPresetHelper($application)
        );

        $response = $controller->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the preset could not be read', $response->subject);
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
     * What the action does when the work behind it fails.
     *
     * Editing a preset reads it by id before the template is rendered; when that read throws,
     * the caller must be told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function editReportsAFailureBehindItRatherThanEscaping(): void
    {
        $itemPresetService = $this->createStub(ItemPresetService::class);
        $itemPresetService->method('getById')
                           ->willThrowException(new RuntimeException('the preset could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $controller = new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'edit'),
            $itemPresetService,
            $this->itemPresetHelper($application)
        );

        $response = $controller->editAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the preset could not be read', $response->subject);
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
     * What the action does when the work behind it fails.
     *
     * Deleting a single preset removes it before notifying; when the removal throws, the caller
     * must be told rather than left with a blank page or an escaping fatal.
     *
     * @throws Exception
     */
    #[Test]
    public function deletingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $itemPresetService = $this->createStub(ItemPresetService::class);
        $itemPresetService->method('delete')
                           ->willThrowException(new RuntimeException('the preset could not be deleted'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'itemPreset', 'delete'),
            $itemPresetService
        ))->deleteAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the preset could not be deleted', $response->subject);
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

    // No catch-arm test for saveCreateAction(): the form it validates first
    // (ItemPresetSaveBase constructs a real, un-injectable ItemsPresetForm) always throws
    // ValidationException before $itemPresetService->create() is reached — the request stub
    // this harness builds answers null to analyzeString('type'), so
    // ItemsPresetForm::analyzeRequestData() hits its default case and throws every time. That
    // lands in the action's earlier `catch (ValidationException $e)` block, which returns
    // directly without calling processException() or notifying an 'exception' event, so it can
    // never exercise the general `catch (Exception $e)` arm this file is testing.

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
