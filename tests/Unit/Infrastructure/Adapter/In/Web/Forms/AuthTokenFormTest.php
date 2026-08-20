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
use SP\Infrastructure\Adapter\In\Web\Forms\AuthTokenForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The form asks for a token password exactly when a vault will be built for the token.
 *
 * A token's vault is the master password, sealed with the token's own password and the token
 * itself. `AuthToken::injectSecureData()` builds one for `SECURED_ACTIONS` *and* for
 * `CAN_USE_SECURE_TOKEN_ACTIONS` — the three view actions that need the master password when a
 * caller asks for custom fields — but this form only demanded a password for the first list.
 *
 * A token for `ACCOUNT_VIEW`, `CATEGORY_VIEW` or `CLIENT_VIEW` could therefore be created with the
 * password field left blank, and its vault was sealed with the empty string. Nothing can open it:
 * `Api::getMasterPassFromVault()` reads `tokenPass` as a required parameter, which refuses the
 * empty string, so the one password that would work cannot be presented. The administrator was
 * told the token had been created, and it could never do what it was created for.
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
final class AuthTokenFormTest extends UnitaryTestCase
{
    private const A_USER = 3;

    private MockObject|RequestService $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(RequestService::class);
    }

    /**
     * Every action a vault gets built for. The first three are the ones the form used to let
     * through; the last two it always refused, and must go on refusing.
     *
     * @return array<string, array{int}>
     */
    public static function actionsThatCarryAVaultProvider(): array
    {
        return [
            'ACCOUNT_VIEW' => [AclActionsInterface::ACCOUNT_VIEW],
            'CATEGORY_VIEW' => [AclActionsInterface::CATEGORY_VIEW],
            'CLIENT_VIEW' => [AclActionsInterface::CLIENT_VIEW],
            'ACCOUNT_VIEW_PASS' => [AclActionsInterface::ACCOUNT_VIEW_PASS],
            'ACCOUNT_CREATE' => [AclActionsInterface::ACCOUNT_CREATE],
        ];
    }

    #[Test]
    #[DataProvider('actionsThatCarryAVaultProvider')]
    public function aTokenThatWillCarryAVaultCannotBeCreatedWithoutAPassword(int $actionId): void
    {
        $this->givenARequestFor($actionId, password: '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Password cannot be blank');

        $this->buildForm()->validateFor(AclActionsInterface::AUTHTOKEN_CREATE);
    }

    #[Test]
    #[DataProvider('actionsThatCarryAVaultProvider')]
    public function theSameTokenIsAcceptedWithOne(int $actionId): void
    {
        $this->givenARequestFor($actionId, password: 'a-token-password');

        $form = $this->buildForm()->validateFor(AclActionsInterface::AUTHTOKEN_CREATE);

        self::assertSame('a-token-password', $form->getItemData()->getHash());
        self::assertSame($actionId, $form->getItemData()->getActionId());
    }

    /**
     * The control, and the reason this is not simply "always demand a password": most actions
     * carry no vault at all, and a token for one of them is meant to be usable without one.
     */
    #[Test]
    public function aTokenThatCarriesNoVaultStillNeedsNoPassword(): void
    {
        $this->givenARequestFor(AclActionsInterface::CATEGORY_SEARCH, password: '');

        $form = $this->buildForm()->validateFor(AclActionsInterface::AUTHTOKEN_CREATE);

        self::assertSame(AclActionsInterface::CATEGORY_SEARCH, $form->getItemData()->getActionId());
    }

    /**
     * Refreshing re-seals the vault whatever the action, so it has always needed the password —
     * unchanged here, and asserted so the shared predicate cannot quietly drop it.
     */
    #[Test]
    public function refreshingAlwaysNeedsThePassword(): void
    {
        $this->givenARequestFor(AclActionsInterface::CATEGORY_SEARCH, password: '', refresh: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Password cannot be blank');

        $this->buildForm()->validateFor(AclActionsInterface::AUTHTOKEN_CREATE);
    }

    /**
     * The two checks that come before the password one, so a refusal above cannot be the form
     * rejecting the fixture for some other reason.
     */
    #[Test]
    public function theUserAndTheActionAreBothRequired(): void
    {
        $this->givenARequestFor(0, password: 'p');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Action not set');

        $this->buildForm()->validateFor(AclActionsInterface::AUTHTOKEN_CREATE);
    }

    private function givenARequestFor(int $actionId, string $password, bool $refresh = false): void
    {
        $this->request->method('analyzeBool')->willReturn($refresh);
        $this->request->method('analyzeInt')->willReturnMap(
            [
                ['users', null, self::A_USER],
                ['actions', null, $actionId],
            ]
        );
        $this->request->method('analyzeEncrypted')->willReturn($password);
    }

    private function buildForm(): AuthTokenForm
    {
        return new AuthTokenForm($this->application, $this->request);
    }
}
