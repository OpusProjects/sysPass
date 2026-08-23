<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Account\PublicLinkType;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\PublicLinkForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The public link form's first tests.
 *
 * A public link is the one way an account is shown to somebody who is not signed in, so what this
 * form insists on before one is made is worth stating.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class PublicLinkFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * Absent and zero alike: `analyzeInt()` answers null for a field that was never sent, and a
     * check written against `0` alone would let that through — which is the mistake
     * `CustomFieldDefForm` made with its own required ints.
     */
    #[Test]
    public function aLinkNeedsAnAccount(): void
    {
        foreach ([null, 0] as $accountId) {
            $request = $this->createMock(RequestService::class);
            $request->method('analyzeInt')->willReturn($accountId);
            $request->method('analyzeBool')->willReturn(false);

            try {
                (new PublicLinkForm($this->application, $request))
                    ->validateFor(AclActionsInterface::PUBLICLINK_CREATE);

                self::fail(sprintf('accountId %s was accepted', var_export($accountId, true)));
            } catch (ValidationException $e) {
                self::assertSame('An account is needed', $e->getMessage());
            }
        }
    }

    /**
     * The type is fixed by the form rather than taken from the request — a caller cannot ask for
     * a link to something other than an account.
     */
    #[Test]
    public function aLinkIsForAnAccountAndSaysSo(): void
    {
        $this->request->method('analyzeInt')->willReturn(42);
        $this->request->method('analyzeBool')->willReturn(true);

        $form = (new PublicLinkForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::PUBLICLINK_CREATE, 3);

        self::assertSame(42, $form->getItemData()->getItemId());
        self::assertSame(PublicLinkType::Account->value, $form->getItemData()->getTypeId());
        self::assertSame(3, $form->getItemData()->getId());
        self::assertTrue($form->getItemData()->isNotify());
    }

    /**
     * The control: the checks belong to create and edit, not to every action.
     */
    #[Test]
    public function anUnrelatedActionIsNotValidated(): void
    {
        $this->request->method('analyzeInt')->willReturn(null);
        $this->request->method('analyzeBool')->willReturn(false);

        $form = (new PublicLinkForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::PUBLICLINK_DELETE);

        self::assertNull($form->getItemData());
    }
}
