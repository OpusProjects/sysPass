<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\TagForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The tag form's first tests.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class TagFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    #[Test]
    public function aTagNeedsAName(): void
    {
        $this->request->method('analyzeString')->willReturn('');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A tag name is needed');

        (new TagForm($this->application, $this->request))->validateFor(AclActionsInterface::TAG_CREATE);
    }

    /**
     * `analyzeString()` answers null for a field that was never sent, which a check written
     * against the empty string alone would let through.
     */
    #[Test]
    public function anAbsentNameIsRefusedToo(): void
    {
        $this->request->method('analyzeString')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A tag name is needed');

        (new TagForm($this->application, $this->request))->validateFor(AclActionsInterface::TAG_EDIT);
    }

    #[Test]
    public function aNamedTagIsAcceptedAndKeepsTheIdItWasValidatedFor(): void
    {
        $this->request->method('analyzeString')->willReturn('a-tag');

        $form = (new TagForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::TAG_EDIT, 12);

        self::assertSame('a-tag', $form->getItemData()->getName());
        self::assertSame(12, $form->getItemData()->getId());
    }

    /**
     * The control: the checks belong to create and edit, not to every action.
     */
    #[Test]
    public function anUnrelatedActionIsNotValidated(): void
    {
        $this->request->method('analyzeString')->willReturn('');

        $form = (new TagForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::TAG_DELETE);

        self::assertNull($form->getItemData());
    }
}
