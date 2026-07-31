<?php
declare(strict_types=1);

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\AccountForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * Class AccountFormTest
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class AccountFormTest extends UnitaryTestCase
{
    /**
     * An action the form does not handle must fail as a ValidationException,
     * not an \UnhandledMatchError (which would surface as a 500).
     */
    public function testValidateForUnhandledActionThrowsValidationException(): void
    {
        $form = new AccountForm(
            $this->application,
            $this->createMock(RequestService::class),
            $this->createMock(AccountPresetService::class)
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid action');

        $form->validateFor(AclActionsInterface::ACCOUNT_DELETE);
    }

    /**
     * Dto::fromArray() matches constructor parameter names, so the array this form builds has to be
     * keyed by them. It used 'private'/'privateGroup' while AccountDto declares $isPrivate and
     * $isPrivateGroup, so both flags arrived null and every account was stored with the privacy the
     * form asked for silently dropped — the checkbox did nothing, on create and on edit.
     */
    public function testPrivateFlagsReachTheDto(): void
    {
        $request = $this->createMock(RequestService::class);

        $request->method('analyzeString')->willReturnCallback(
            static fn(string $param) => $param === 'name' ? 'an_account' : null
        );
        $request->method('analyzeInt')->willReturnCallback(
            static fn(string $param) => in_array($param, ['client_id', 'category_id'], true) ? 1 : null
        );
        $request->method('analyzeEncrypted')->willReturn('a_password');
        $request->method('analyzeBool')->willReturnCallback(
            static fn(string $param) => in_array($param, ['private_enabled', 'private_group_enabled'], true)
        );

        $accountPresetService = $this->createMock(AccountPresetService::class);
        $accountPresetService->method('checkPasswordPreset')->willReturnArgument(0);

        $form = new AccountForm($this->application, $request, $accountPresetService);

        $accountDto = $form->validateFor(AclActionsInterface::ACCOUNT_CREATE)->getItemData();

        // AccountDto types both as ?bool, so the form's int cast arrives coerced.
        self::assertTrue($accountDto->isPrivate);
        self::assertTrue($accountDto->isPrivateGroup);
    }
}
