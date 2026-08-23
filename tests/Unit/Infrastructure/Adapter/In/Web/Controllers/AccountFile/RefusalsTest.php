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

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\AccountFile;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Account\Ports\AccountFileService;
use SP\Application\Application;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\UI\ThemeIconsInterface;
use SP\Domain\Core\UI\ThemeInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Controllers\AccountFile\SearchController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Helpers\Grid\FileGrid;
use SP\Infrastructure\Adapter\In\Web\View\TemplateInterface;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What this action does when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so a
 * request dispatched through it cannot be denied and the refusal branch of every action goes
 * unexercised. This mocks the ACL closed and asserts both halves: the refusal reaches the caller,
 * and the service behind the controller is never asked to do the thing that was refused.
 *
 * `SearchController` is the only controller in this family that carries the `checkUserAccess`
 * guard, and it does not carry it itself — it inherits `searchAction()` from
 * `SearchGridControllerBase`, which has no `try`/`catch` around it, so there is no second,
 * catch-arm test to add here: an exception from a collaborator would simply propagate rather than
 * come back as an `ActionResponse::error()`. `DeleteController`, `DownloadController`,
 * `ListController`, `UploadController` and `ViewController` gate on
 * `AccountFileAcl::requireEdit()`/`requireView()` instead of `AclInterface::checkUserAccess()`, so
 * they carry no guard of this shape at all.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function searchingIsRefusedWhenTheAclDenies(): void
    {
        $accountFileService = $this->createMock(AccountFileService::class);
        $accountFileService->expects(self::never())->method('search');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SearchController(
            $application,
            $this->webControllerHelper($acl, $application, 'accountFile', 'search'),
            $this->fileGrid($application, $acl),
            $accountFileService
        ))->searchAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    private function fileGrid(Application $application, AclInterface $acl): FileGrid
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getIcons')->willReturn($this->createStub(ThemeIconsInterface::class));

        return new FileGrid(
            $application,
            $this->createStub(TemplateInterface::class),
            $this->createStub(RequestService::class),
            $acl,
            $theme
        );
    }
}
