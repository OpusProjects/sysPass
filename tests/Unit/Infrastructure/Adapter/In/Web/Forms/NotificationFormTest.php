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
use SP\Domain\User\Dtos\UserDto;
use SP\Infrastructure\Adapter\In\Web\Forms\NotificationForm;
use SP\Tests\Support\UnitaryTestCase;

/**
 * A notification is a message addressed to somebody, and each of the four fields the form insists
 * on is one half of that: what it is about, what kind it is, what it says, and who it is for. A
 * notification saved without one of them either never reaches anybody or reaches them saying
 * nothing, and neither failure is visible to whoever sent it.
 *
 * The last rule is the one worth stating: a notification with no user is only allowed when it is
 * addressed to administrators or pinned for everybody — and only an application administrator can
 * make it either of those.
 */
#[Group('unitary')]
class NotificationFormTest extends UnitaryTestCase
{
    private RequestService|Stub $request;

    protected function setUp(): void
    {
        parent::setUp();

        // A stub, not a mock: the form only reads from the request.
        $this->request = self::createStub(RequestService::class);
    }

    /**
     * @throws ValidationException
     */
    #[Test]
    public function aNotificationWithoutAComponentIsRefused(): void
    {
        $this->givenAForm(['notification_component' => '']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A component is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * @throws ValidationException
     */
    #[Test]
    public function aNotificationWithoutATypeIsRefused(): void
    {
        $this->givenAForm(['notification_type' => '']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A type is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * @throws ValidationException
     */
    #[Test]
    public function aNotificationWithoutADescriptionIsRefused(): void
    {
        $this->givenAForm(['notification_description' => '']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A description is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * With no user and neither of the two flags that stand in for one, there is nobody to deliver
     * it to.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aNotificationAddressedToNobodyIsRefused(): void
    {
        $this->givenAForm([], ['notification_user' => 0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A target is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * An application administrator may address one to administrators generally instead of to a
     * person, which is how the instance-wide notices are written.
     *
     * @throws ValidationException
     */
    #[Test]
    public function anAdministratorMayAddressOneToAdministratorsInsteadOfAPerson(): void
    {
        $this->asApplicationAdministrator();
        $this->givenAForm([], ['notification_user' => 0], true);

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::NOTIFICATION_CREATE);

        self::assertTrue($form->getItemData()->isOnlyAdmin());
        self::assertTrue($form->getItemData()->isSticky());
    }

    /**
     * And somebody who is not one cannot: the two flags are only read for an administrator, so a
     * request setting them arrives with nobody to deliver to and is refused. Otherwise any user
     * could pin a notification in front of everybody.
     *
     * @throws ValidationException
     */
    #[Test]
    public function anOrdinaryUserCannotPinOneForEverybody(): void
    {
        $this->givenAForm([], ['notification_user' => 0], true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A target is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * A complete one carries what was submitted, with the description wrapped as the markup the
     * page renders — it is composed here rather than at display time.
     *
     * @throws ValidationException
     */
    #[Test]
    public function aCompleteNotificationCarriesWhatWasSubmitted(): void
    {
        $this->givenAForm(
            [
                'notification_type' => 'sysPass',
                'notification_component' => 'Accounts',
                'notification_description' => 'Something happened',
            ],
            ['notification_user' => 7]
        );

        $form = $this->buildForm();
        $form->validateFor(AclActionsInterface::NOTIFICATION_EDIT, 100);

        $notification = $form->getItemData();

        self::assertSame(100, $notification->getId());
        self::assertSame('sysPass', $notification->getType());
        self::assertSame('Accounts', $notification->getComponent());
        self::assertSame(7, $notification->getUserId());
        self::assertStringContainsString('Something happened', $notification->getDescription());
    }

    /**
     * Any other action leaves the form alone — nothing is analysed and nothing is built.
     *
     * @throws ValidationException
     */
    #[Test]
    public function anActionThatDoesNotSubmitANotificationIsNotValidated(): void
    {
        $form = $this->buildForm();

        $form->validateFor(AclActionsInterface::NOTIFICATION_DELETE);

        self::assertNull($form->getItemData());
    }

    /**
     * @param array<string, string> $strings overriding the complete, valid submission
     * @param array<string, int> $ints
     */
    private function givenAForm(array $strings = [], array $ints = [], bool $bools = false): void
    {
        $strings += [
            'notification_type' => 'sysPass',
            'notification_component' => 'Accounts',
            'notification_description' => 'Something happened',
        ];

        $ints += ['notification_user' => 7];

        $this->request
            ->method('analyzeString')
            ->willReturnCallback(static fn(string $name, ?string $default = null) => $strings[$name] ?? $default);

        $this->request
            ->method('analyzeInt')
            ->willReturnCallback(static fn(string $name, ?int $default = null) => $ints[$name] ?? $default);

        $this->request->method('analyzeBool')->willReturn($bools);
    }

    private function asApplicationAdministrator(): void
    {
        $this->context->setUserData(
            UserDto::fromArray(['id' => 1, 'login' => 'admin', 'isAdminApp' => true])
        );
    }

    private function buildForm(): NotificationForm
    {
        return new NotificationForm($this->application, $this->request);
    }
}
