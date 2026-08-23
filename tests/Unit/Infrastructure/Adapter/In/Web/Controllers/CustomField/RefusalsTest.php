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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\CustomField;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use RuntimeException;
use SP\Application\Application;
use SP\Application\CustomField\Ports\CustomFieldDefinitionService;
use SP\Application\CustomField\Ports\CustomFieldTypeService;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\DeleteController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\EditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\SaveCreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\SaveEditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\SearchController;
use SP\Infrastructure\Adapter\In\Web\Controllers\CustomField\ViewController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\CustomFieldGrid;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no, and what they do when the work behind it fails.
 *
 * The integration harness binds an ACL that answers `true` to everything, so a request dispatched
 * through it cannot be refused. These mock it closed and assert both halves: the refusal reaches
 * the caller, and the service behind the controller is never asked for anything.
 *
 * Create, Delete, Edit and View each wrap their body in a `try`/`catch` that logs the exception,
 * announces it and answers with its message, so each of those four also gets a catch-arm test:
 * the ACL allows, a collaborator throws, and the answer is the thrown message rather than an
 * escaping fatal.
 *
 * SaveCreate and SaveEdit have the same `try`/`catch`, but their body calls the real
 * `CustomFieldDefForm::validateFor()` before it ever reaches `customFieldDefService` — and this
 * harness's request stub answers every field with `null`, so `checkCommon()` always throws its own
 * `ValidationException('Field name not set')` first. There is no way, from this harness, to make
 * `customFieldDefService` the thing that throws for either of those two actions, so their catch-arm
 * test is skipped; the refusal test still stands; since the ACL check runs before the form is
 * touched at all.
 *
 * Search has no `try`/`catch` of its own (it is not built on `CustomFieldSaveBase`/
 * `CustomFieldViewBase`, and defines `searchAction()` itself without one), so it only gets a
 * refusal test, the same as its siblings in the Category/Client/AuthToken families.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * A real (final, so undoubled) grid — the search action never reaches it once the ACL check
     * has already returned, but the controller's constructor still requires a concrete instance.
     *
     * @throws Exception
     */
    private function customFieldGrid(Application $application): CustomFieldGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new CustomFieldGrid(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class),
            $this->createStub(AclInterface::class),
            $theme
        );
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldTypeService = $this->createMock(CustomFieldTypeService::class);
        $customFieldTypeService->expects(self::never())->method('getAll');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'create'),
            $this->createStub(CustomFieldDefinitionService::class),
            $customFieldTypeService
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('delete');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'delete'),
            $customFieldDefService
        ))->deleteAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'edit'),
            $customFieldDefService,
            $this->createStub(CustomFieldTypeService::class)
        ))->editAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingACreateIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'saveCreate'),
            $customFieldDefService
        ))->saveCreateAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('getById');
        $customFieldDefService->expects(self::never())->method('update');
        $customFieldDefService->expects(self::never())->method('changeModule');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'saveEdit'),
            $customFieldDefService
        ))->saveEditAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'view'),
            $customFieldDefService,
            $this->createStub(CustomFieldTypeService::class)
        ))->viewAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function searchingIsRefusedWhenTheAclDenies(): void
    {
        $customFieldDefService = $this->createMock(CustomFieldDefinitionService::class);
        $customFieldDefService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SearchController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'search'),
            $customFieldDefService,
            $this->customFieldGrid($application)
        ))->searchAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function creatingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $customFieldTypeService = $this->createStub(CustomFieldTypeService::class);
        $customFieldTypeService->method('getAll')
                               ->willThrowException(new RuntimeException('the field types could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'create'),
            $this->createStub(CustomFieldDefinitionService::class),
            $customFieldTypeService
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the field types could not be read', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $customFieldDefService = $this->createStub(CustomFieldDefinitionService::class);
        $customFieldDefService->method('delete')
                              ->willThrowException(new RuntimeException('the field could not be deleted'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'delete'),
            $customFieldDefService
        ))->deleteAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the field could not be deleted', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function editingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $customFieldDefService = $this->createStub(CustomFieldDefinitionService::class);
        $customFieldDefService->method('getById')
                              ->willThrowException(new RuntimeException('the field could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new EditController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'edit'),
            $customFieldDefService,
            $this->createStub(CustomFieldTypeService::class)
        ))->editAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the field could not be read', $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingReportsAFailureBehindItRatherThanEscaping(): void
    {
        $customFieldDefService = $this->createStub(CustomFieldDefinitionService::class);
        $customFieldDefService->method('getById')
                              ->willThrowException(new RuntimeException('the field could not be read'));

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatAllows();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'customField', 'view'),
            $customFieldDefService,
            $this->createStub(CustomFieldTypeService::class)
        ))->viewAction(123);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame('the field could not be read', $response->subject);
    }
}
