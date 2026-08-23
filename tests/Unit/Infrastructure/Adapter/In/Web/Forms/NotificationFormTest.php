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
     * A notice addressed to nobody keeps the flags that only mean anything on one.
     *
     * The form's "Select User" option posts an empty string, which `analyzeInt()` reads as null,
     * and the guard compared against `0` — so `null === 0` was false and both flags were dropped on
     * exactly the notifications they exist for. `onlyAdmin` is the one that matters: a regular
     * user's notifications are selected with
     * `(userId = :userId OR (userId IS NULL AND onlyAdmin = 0) OR sticky = 1)`, so an
     * administrator ticking "only admins" and losing it was then left with a notification that
     * had no user, no `onlyAdmin` and no `sticky` — which is exactly what the target check below
     * refuses. So a broadcast could not be created through this form at all: the submission came
     * back as "A target is needed", naming the one thing the administrator had in fact supplied.
     * The REST door sets both flags independently of the user and could always create them.
     */
    #[Test]
    public function anAdministratorsBroadcastKeepsItsFlags(): void
    {
        $this->asApplicationAdministrator();
        // null is what an unselected user posts; 0 is the other way of saying "nobody".
        $this->givenAForm(ints: ['notification_user' => null], bools: true);

        $data = $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE)->getItemData();

        self::assertNull($data->getUserId(), 'a broadcast is addressed to nobody');
        self::assertTrue((bool)$data->isOnlyAdmin(), 'an admin-only notice must stay admin-only');
        self::assertTrue((bool)$data->isSticky());
    }

    /**
     * The same submission from somebody who is not an application administrator is refused, so the
     * fix widened the null case rather than the permission: broadcasting stays an administrator's
     * to do, and a notice with no user and no flags has nobody to reach.
     */
    #[Test]
    public function aRegularUserCannotBroadcast(): void
    {
        $this->givenAForm(ints: ['notification_user' => null], bools: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('A target is needed');

        $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE);
    }

    /**
     * And a notice addressed to a particular user still gets neither, whoever sends it: the flags
     * describe a broadcast, and this one is not.
     */
    #[Test]
    public function aNoticeAddressedToSomebodyGetsNoFlags(): void
    {
        $this->asApplicationAdministrator();
        $this->givenAForm(ints: ['notification_user' => 7], bools: true);

        $data = $this->buildForm()->validateFor(AclActionsInterface::NOTIFICATION_CREATE)->getItemData();

        self::assertSame(7, $data->getUserId());
        self::assertNotTrue($data->isOnlyAdmin());
        self::assertNotTrue($data->isSticky());
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

        // array_key_exists, not `+=`: a deliberate null override must survive, and `+=` only
        // fills keys that are absent — which a null one is not.
        if (!array_key_exists('notification_user', $ints)) {
            $ints['notification_user'] = 7;
        }

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
