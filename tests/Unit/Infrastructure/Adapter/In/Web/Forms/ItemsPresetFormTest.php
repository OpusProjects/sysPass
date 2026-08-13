<?php
declare(strict_types=1);
/**
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Tests\Unit\Infrastructure\Adapter\In\Web\Forms;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ValidationException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\ItemPreset\Models\AccountPermission;
use SP\Domain\ItemPreset\Models\AccountPrivate;
use SP\Domain\ItemPreset\Models\Password;
use SP\Domain\ItemPreset\Models\SessionTimeout;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Infrastructure\Adapter\In\Web\Forms\ItemsPresetForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The presets an administrator sets here are the rules everybody else's accounts then obey: who an
 * account is shared with by default, whether it is private, how long a session lasts from a given
 * address, and what a password has to look like.
 *
 * A preset that saves without the field it needs is worse than one that is refused: it applies
 * silently to every account created afterwards. So what is pinned here is the refusals, and — for
 * each kind of preset — that the values submitted are the values it carries.
 */
#[Group('unitary')]
class ItemsPresetFormTest extends UnitaryTestCase
{
    private RequestService|Stub $request;

    protected function setUp(): void
    {
        parent::setUp();

        // A stub, not a mock: the form only reads from the request, so there is nothing to expect
        // of it — and PHPUnit now says so out loud for an unexpecting mock.
        $this->request = self::createStub(RequestService::class);
    }

    /**
     * A preset with no kind — or one naming a kind the application does not have — is refused
     * rather than stored as an empty rule nothing can apply.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPresetOfAnUnknownKindIsRefused(): void
    {
        $this->givenARequest(['type' => 'something-that-is-not-a-preset']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Value type not set or incorrect');

        $this->buildForm()->validateFor(AclActionsInterface::ITEMPRESET_CREATE);
    }

    /**
     * The point of a permission preset is the permissions in it. One naming nobody at all would
     * apply to every new account and grant nothing, which is not a rule anybody meant to write.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPermissionPresetGrantingNobodyAnythingIsRefused(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION],
            ['user_id' => 1]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('There aren\'t any defined permissions');

        $this->buildForm()->validateFor(AclActionsInterface::ITEMPRESET_CREATE);
    }

    /**
     * And one that does name somebody carries exactly who was named, in the right direction:
     * viewers are not editors.
     *
     * The preset object itself only exists serialized into the stored row, so it is read back the
     * way the application reads it: hydrated off the model.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPermissionPresetCarriesWhoWasNamed(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION],
            ['user_id' => 1],
            ['users_view' => [10, 11], 'users_edit' => [12], 'user_groups_view' => [20], 'user_groups_edit' => []]
        );

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::ITEMPRESET_CREATE);

        $preset = $form->getItemData()->getItemPreset()->hydrate(AccountPermission::class);

        self::assertInstanceOf(AccountPermission::class, $preset);
        self::assertSame([10, 11], $preset->getUsersView());
        self::assertSame([12], $preset->getUsersEdit());
        self::assertSame([20], $preset->getUserGroupsView());
        self::assertSame([], $preset->getUserGroupsEdit());
    }

    /**
     * A session timeout is bound to an address, so an address that is not one cannot be stored:
     * the rule would either never match or match everybody.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aSessionTimeoutForAnAddressThatIsNotOneIsRefused(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT, 'ip_address' => 'not-an-address'],
            ['user_id' => 1, 'timeout' => 300]
        );

        $this->expectException(ValidationException::class);

        $this->buildForm()->validateFor(AclActionsInterface::ITEMPRESET_CREATE);
    }

    /**
     * A valid one keeps the address and the timeout it was given.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aSessionTimeoutCarriesItsAddressAndTimeout(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT, 'ip_address' => '192.168.0.1'],
            ['user_id' => 1, 'timeout' => 300]
        );

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::ITEMPRESET_CREATE);

        $preset = $form->getItemData()->getItemPreset()->hydrate(SessionTimeout::class);

        self::assertInstanceOf(SessionTimeout::class, $preset);
        self::assertSame('192.168.0.1', $preset->getAddress());
        self::assertSame(300, $preset->getTimeout());
    }

    /**
     * A password preset can carry a pattern generated passwords must match. An expression that
     * does not compile is refused here rather than at the moment somebody tries to save a
     * password against it, which is where it would otherwise surface.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPasswordPresetWithAnExpressionThatDoesNotCompileIsRefused(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD],
            ['user_id' => 1],
            [],
            '([unclosed'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid regular expression');

        $this->buildForm()->validateFor(AclActionsInterface::ITEMPRESET_CREATE);
    }

    /**
     * A password preset with no expression at all is fine — the rest of the rule (length, the
     * character classes, the score) stands on its own.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPasswordPresetWithoutAnExpressionIsAccepted(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD],
            ['user_id' => 1, 'length' => 16, 'score' => 4],
            [],
            ''
        );

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::ITEMPRESET_CREATE);

        $preset = $form->getItemData()->getItemPreset()->hydrate(Password::class);

        self::assertInstanceOf(Password::class, $preset);
        self::assertSame(16, $preset->getLength());
        self::assertSame(4, $preset->getScore());
    }

    /**
     * The private-account preset has nothing of its own to validate: both of its flags are
     * booleans, and either combination is a rule somebody might mean.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPrivateAccountPresetCarriesItsTwoFlags(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE],
            ['user_id' => 1],
            [],
            null,
            true
        );

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::ITEMPRESET_CREATE);

        $preset = $form->getItemData()->getItemPreset()->hydrate(AccountPrivate::class);

        self::assertInstanceOf(AccountPrivate::class, $preset);
        self::assertTrue($preset->isPrivateUser());
        self::assertTrue($preset->isPrivateGroup());
    }

    /**
     * Every preset applies to somebody: a user, a group or a profile. One naming none of the three
     * would be a rule with nobody to apply it to — and, depending on how it were read later, one
     * that applies to everybody.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aPresetThatAppliesToNobodyIsRefused(): void
    {
        $this->givenARequest(
            ['type' => ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE],
            []
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('An element of type user, group or profile need to be set');

        $this->buildForm()->validateFor(AclActionsInterface::ITEMPRESET_CREATE);
    }

    /**
     * Any other action leaves the form alone: validateFor() only analyses the request for the two
     * actions that submit one, so a call for anything else neither builds a preset nor raises.
     *
     * @throws ValidationException
     */
    #[Test]
    public function anActionThatDoesNotSubmitAPresetIsNotValidated(): void
    {
        $form = $this->buildForm();

        $form->validateFor(AclActionsInterface::ITEMPRESET_DELETE);

        self::assertNull($form->getItemData());
    }

    /**
     * @param array<string, string> $strings
     * @param array<string, int> $ints
     * @param array<string, int[]> $arrays
     */
    private function givenARequest(
        array   $strings,
        array   $ints = ['user_id' => 1],
        array   $arrays = [],
        ?string $unsafeString = null,
        bool    $bools = false
    ): void {
        $this->request
            ->method('analyzeString')
            ->willReturnCallback(static fn(string $name, ?string $default = null) => $strings[$name] ?? $default);

        $this->request
            ->method('analyzeInt')
            ->willReturnCallback(static fn(string $name, ?int $default = null) => $ints[$name] ?? $default);

        $this->request
            ->method('analyzeArray')
            ->willReturnCallback(
                static fn(string $name, ?callable $mapper = null, mixed $default = null) => $arrays[$name] ?? $default
            );

        $this->request->method('analyzeBool')->willReturn($bools);
        $this->request->method('analyzeUnsafeString')->willReturn($unsafeString);
    }

    private function buildForm(): ItemsPresetForm
    {
        return new ItemsPresetForm($this->application, $this->request);
    }
}
