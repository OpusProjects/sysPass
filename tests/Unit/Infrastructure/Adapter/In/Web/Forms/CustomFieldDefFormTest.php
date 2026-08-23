<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\CustomFieldDefForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The custom field definition form's first tests.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class CustomFieldDefFormTest extends UnitaryTestCase
{
    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function missingIntProvider(): array
    {
        return [
            // What `analyzeInt()` answers for a field that was never sent, and for one it cannot
            // read as an int — "abc" and "" both fall back to the default too.
            'absent' => [null],
            'zero' => [0],
        ];
    }

    /**
     * A definition needs a type, and an absent one is not zero.
     *
     * `0 === null` is false, so `0 === getTypeId()` let an absent type straight through.
     * `CustomFieldDefinition.typeId` is NOT NULL, so what should have been this message arrived as
     * a database constraint error instead. `AuthTokenForm` carries a comment about making exactly
     * this mistake with `users` and `actions`.
     */
    #[Test]
    #[DataProvider('missingIntProvider')]
    public function aDefinitionNeedsAType(?int $typeId): void
    {
        $this->givenARequest(name: 'a field', typeId: $typeId, moduleId: 10);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field type not set');

        $this->buildForm()->validateFor(AclActionsInterface::CUSTOMFIELD_CREATE);
    }

    /**
     * And a module, which `moduleId`'s NOT NULL column says the same about.
     */
    #[Test]
    #[DataProvider('missingIntProvider')]
    public function aDefinitionNeedsAModule(?int $moduleId): void
    {
        $this->givenARequest(name: 'a field', typeId: 1, moduleId: $moduleId);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field module not set');

        $this->buildForm()->validateFor(AclActionsInterface::CUSTOMFIELD_EDIT);
    }

    #[Test]
    public function aDefinitionNeedsAName(): void
    {
        $this->givenARequest(name: '', typeId: 1, moduleId: 10);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field name not set');

        $this->buildForm()->validateFor(AclActionsInterface::CUSTOMFIELD_CREATE);
    }

    /**
     * The control on all of the above: a complete definition is accepted, and its flags are the
     * ints the model declares rather than the bools the request answered.
     */
    #[Test]
    public function aCompleteDefinitionIsAccepted(): void
    {
        $this->givenARequest(name: 'a field', typeId: 1, moduleId: 10, flags: true);

        $data = $this->buildForm()->validateFor(AclActionsInterface::CUSTOMFIELD_CREATE, 5)->getItemData();

        self::assertSame('a field', $data->getName());
        self::assertSame(1, $data->getTypeId());
        self::assertSame(10, $data->getModuleId());
        self::assertSame(5, $data->getId());
        self::assertSame(1, $data->getRequired());
        self::assertSame(1, $data->getIsEncrypted());
    }

    #[Test]
    public function anUnrelatedActionIsNotValidated(): void
    {
        $this->givenARequest(name: '', typeId: null, moduleId: null);

        $form = $this->buildForm()->validateFor(AclActionsInterface::CUSTOMFIELD_DELETE);

        self::assertNull($form->getItemData());
    }

    private function givenARequest(?string $name, ?int $typeId, ?int $moduleId, bool $flags = false): void
    {
        $this->request->method('analyzeString')->willReturnMap(
            [
                ['name', null, $name],
                ['help', null, 'some help'],
            ]
        );
        $this->request->method('analyzeInt')->willReturnMap(
            [
                ['type', null, $typeId],
                ['module', null, $moduleId],
            ]
        );
        $this->request->method('analyzeBool')->willReturn($flags);
    }

    private function buildForm(): CustomFieldDefForm
    {
        return new CustomFieldDefForm($this->application, $this->request);
    }
}
