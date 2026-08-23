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
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\SaveCreateFromAccountController;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\SaveEditController;
use SP\Infrastructure\Adapter\In\Web\Controllers\PublicLink\ViewController;
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

    /**
     * @throws Exception
     */
    #[Test]
    public function viewingALinkIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('getById');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new ViewController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'view'),
            $publicLinkService,
            $this->createStub(AccountService::class)
        ))->viewAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * Making a link straight from an account is a second door onto the same thing, so it is
     * refused separately rather than assumed to follow from the first.
     *
     * @throws Exception
     */
    #[Test]
    public function creatingALinkFromAnAccountIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('create');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveCreateFromAccountController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'saveCreateFromAccount'),
            $publicLinkService
        ))->saveCreateFromAccountAction(1, 0);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }

    /**
     * @throws Exception
     */
    #[Test]
    public function savingAnEditIsRefusedWhenTheAclDenies(): void
    {
        $publicLinkService = $this->createMock(PublicLinkService::class);
        $publicLinkService->expects(self::never())->method('refresh');

        $application = $this->applicationForASignedInUser();
        $acl = $this->aclThatRefuses();

        $response = (new SaveEditController(
            $application,
            $this->webControllerHelper($acl, $application, 'publicLink', 'saveEdit'),
            $publicLinkService
        ))->saveEditAction(1);

        self::assertSame(ResponseStatus::ERROR, $response->status);
        self::assertSame("You don't have permission to do this operation", $response->subject);
    }
}
