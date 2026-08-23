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
use SP\Infrastructure\Adapter\In\Web\Forms\ClientForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The client form's first tests.
 *
 * A form is where a rule is stated for the web, so what it refuses is worth pinning: the same rule
 * has to hold at the API door, and knowing which rules exist here is what makes that comparison
 * possible.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class ClientFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    #[Test]
    public function aClientNeedsAName(): void
    {
        $this->givenARequest(name: '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A client name needed');

        (new ClientForm($this->application, $this->request))->validateFor(AclActionsInterface::CLIENT_CREATE);
    }

    /**
     * Absent rather than blank: `analyzeString()` answers null for a field that was never sent, and
     * a check written against the empty string alone would let that through.
     */
    #[Test]
    public function anAbsentNameIsRefusedToo(): void
    {
        $this->givenARequest(name: null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A client name needed');

        (new ClientForm($this->application, $this->request))->validateFor(AclActionsInterface::CLIENT_EDIT);
    }

    #[Test]
    public function aNamedClientIsAccepted(): void
    {
        $this->givenARequest(name: 'A Client', description: 'about it', isGlobal: true);

        $form = (new ClientForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::CLIENT_CREATE, 7);

        self::assertSame('A Client', $form->getItemData()->getName());
        self::assertSame('about it', $form->getItemData()->getDescription());
        self::assertSame(7, $form->getItemData()->getId());
        self::assertTrue((bool)$form->getItemData()->getIsGlobal());
    }

    /**
     * The control on every refusal above: an action the form does not validate for is left alone,
     * so the checks are tied to create and edit rather than running for anything at all.
     */
    #[Test]
    public function anUnrelatedActionIsNotValidated(): void
    {
        $this->givenARequest(name: '');

        $form = (new ClientForm($this->application, $this->request))
            ->validateFor(AclActionsInterface::CLIENT_DELETE);

        self::assertNull($form->getItemData());
    }

    private function givenARequest(?string $name, ?string $description = null, bool $isGlobal = false): void
    {
        $this->request->method('analyzeString')->willReturnMap(
            [
                ['name', null, $name],
                ['description', null, $description],
            ]
        );
        $this->request->method('analyzeBool')->willReturn($isGlobal);
    }
}
