<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Controllers\PublicLink;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use SP\Application\Account\Ports\AccountService;
use SP\Application\Account\Ports\PublicLinkService;
use SP\Domain\Common\Enums\ResponseStatus;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\CreateController;
use SP\Tests\Support\WebControllerTestCase;

/**
 * A public link is the one way an account is shown to somebody who is not signed in, so being able
 * to make one is worth refusing properly — and the refusal is unreachable through the integration
 * harness, whose ACL answers `true` to everything.
 */
#[Group('unitary')]
class RefusalsTest extends WebControllerTestCase
{
    /**
     * @throws Exception
     */
    #[Test]
    public function creatingALinkIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new CreateController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'create'),
            $publicLinkService,
            $this->createStub(AccountService::class)
        ))->createAction();

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
