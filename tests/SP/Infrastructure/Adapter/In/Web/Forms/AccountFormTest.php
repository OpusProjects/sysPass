<?php
declare(strict_types=1);

namespace SP\Tests\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use SP\Application\Account\Ports\AccountPresetService;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\Adapter\In\Web\Forms\AccountForm;
use SP\Tests\UnitaryTestCase;

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
}
