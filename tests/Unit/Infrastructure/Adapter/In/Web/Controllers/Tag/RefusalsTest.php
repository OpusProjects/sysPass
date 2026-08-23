<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\Tag;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Application\Tag\Ports\TagService;
use SP\Infrastructure\Adapter\In\Web\Controllers\Tag\CreateController;
use SP\Infrastructure\Adapter\In\Web\Controllers\Tag\ViewController;
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
    public function creatingIsRefusedWhenTheAclDenies(): void
    {
        $service = $this->createMock(TagService::class);
        $service->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'tag', 'create'),
            $service
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
        $service = $this->createMock(TagService::class);
        $service->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'tag', 'view'),
            $service
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
