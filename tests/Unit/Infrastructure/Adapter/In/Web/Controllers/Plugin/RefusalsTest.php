<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Plugin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Domain\Plugin\Ports\PluginManagerService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Plugin\DeleteController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * What these actions do when the ACL says no.
 *
 * `IntegrationTestCase` binds an `AclInterface` double that answers `true` to everything, so a
 * request dispatched through it cannot be denied and the refusal branch of every action goes
 * unexercised. These mock the ACL closed and assert both halves: the refusal reaches the caller,
 * and the service behind the controller is never asked to do the thing that was refused.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{

    /**
     * @throws Exception
     */
    #[Test]
    public function deletingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(PluginManagerService::class);
        $service->expects(self::never())->method('delete');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new DeleteController(
            $application,
            $this->webControllerHelper($acl, $application, 'plugin', 'delete'),
            $service
        ))->deleteAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
