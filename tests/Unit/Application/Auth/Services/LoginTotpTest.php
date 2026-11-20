<?php

declare(strict_types=1);
/*
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

namespace SP\Tests\Unit\Application\Auth\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Auth\Services\LoginTotp;
use SP\Application\Security\Ports\TrackService;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Dtos\TotpProof;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Http\Ports\RequestService;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\Security\Dtos\TrackRequest;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The second factor at sign-in
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class LoginTotpTest extends UnitaryTestCase
{
    private const USER_ID = 7;
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TrackService|MockObject    $trackService;
    private RequestService|MockObject  $request;
    private UserTotpService|MockObject $userTotpService;
    private LoginTotp                  $loginTotp;

    public function testAUserWithoutASecondFactorIsNotAskedForACode(): void
    {
        $this->userTotpService->expects($this->atLeastOnce())->method('isEnabled')->with(self::USER_ID)->willReturn(false);
        $this->request->expects($this->never())->method('analyzeString');

        self::assertNull($this->loginTotp->check($this->user()));
    }

    /**
     * The form does not show the field until it is asked for, so the first submission of every
     * such user arrives without one: that is a question, not a failed attempt.
     */
    public function testAMissingCodeIsAskedForAndNotCounted(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn(null);
        $this->trackService->expects($this->never())->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);
        $this->expectExceptionMessage('Two-factor code needed');

        $this->loginTotp->check($this->user());
    }

    public function testAWrongCodeIsRefusedAndCounted(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->expects($this->atLeastOnce())->method('check')->with(self::USER_ID, '123456')->willReturn(null);
        $this->trackService->expects($this->once())->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);
        $this->expectExceptionMessage('Wrong two-factor code');

        $this->loginTotp->check($this->user());
    }

    public function testTheRightCodeAnswersWithWhatToSpendAndSpendsNothing(): void
    {
        $proof = TotpProof::step(42);

        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->method('check')->willReturn($proof);
        $this->userTotpService->expects($this->never())->method('spend');
        $this->trackService->expects($this->never())->method('add');

        self::assertSame($proof, $this->loginTotp->check($this->user()));
    }

    /**
     * Two sign-ins with the same code both pass check(); the one that loses the race to spend
     * it is refused like a wrong code.
     */
    public function testACodeAnotherSignInSpentFirstIsRefusedAndCounted(): void
    {
        $proof = TotpProof::step(42);

        $this->userTotpService->expects($this->atLeastOnce())->method('spend')->with(self::USER_ID, $proof)->willReturn(false);
        $this->trackService->expects($this->once())->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);

        $this->loginTotp->consume(self::USER_ID, $proof);
    }

    public function testSpendingAFreshStepPasses(): void
    {
        $proof = TotpProof::step(42);

        $this->userTotpService->expects($this->once())->method('spend')->with(self::USER_ID, $proof)->willReturn(true);
        $this->userTotpService->expects($this->never())->method('generateRecoveryCodes');
        $this->trackService->expects($this->never())->method('add');

        self::assertSame([], $this->loginTotp->consume(self::USER_ID, $proof));
    }

    /**
     * A recovery code spent at sign-in is recorded on its own: it is how a lost phone, or a
     * stolen code sheet, shows up in the event log.
     */
    public function testSigningInWithARecoveryCodeIsRecorded(): void
    {
        $events = [];

        $this->userTotpService->method('spend')->willReturn(true);
        $this->application->getEventDispatcher()
                          ->method('notify')
                          ->willReturnCallback(function ($event) use (&$events) {
                              $events[] = $event->getName();
                          });

        $this->loginTotp->consume(self::USER_ID, TotpProof::recoveryCode('a-hash'));

        self::assertContains('login.totp.recovery', $events);
    }

    /**
     * A storage failure is an internal error, never a pass.
     */
    public function testAFailureReadingTheSecretRefusesTheSignIn(): void
    {
        $this->userTotpService->method('isEnabled')->willThrowException(ServiceException::error('boom'));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Internal error');

        $this->loginTotp->check($this->user());
    }

    /**
     * Required per user, or for everybody: either way a user with no second factor enrols one.
     *
     * @return array<string, array{bool, bool}>
     */
    public static function requiredProvider(): array
    {
        return [
            'by the user\'s own flag' => [true, false],
            'for the whole installation' => [false, true],
        ];
    }

    /**
     * Nobody required and nothing enrolled: the password is enough, as before.
     */
    public function testAUserNobodyRequiresIsNotAskedToEnrol(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(false);
        $this->userTotpService->expects($this->never())->method('startEnrolment');

        self::assertNull($this->loginTotp->check($this->user()));
    }

    /**
     * The first attempt is a question, with what the form needs to draw the QR code.
     */
    #[DataProvider('requiredProvider')]
    public function testARequiredUserWithoutOneIsAskedToEnrol(bool $byUser, bool $byInstallation): void
    {
        $this->config->getConfigData()->setTotpRequired($byInstallation);

        $this->userTotpService->method('isEnabled')->willReturn(false);
        $this->userTotpService->method('pendingSecret')->willReturn(null);
        $this->userTotpService
            ->expects($this->once())
            ->method('startEnrolment')
            ->with(self::USER_ID)
            ->willReturn(self::SECRET);
        $this->trackService->expects($this->never())->method('add');

        try {
            $this->loginTotp->check($this->user($byUser));
            self::fail('enrolment was not asked for');
        } catch (AuthException $e) {
            self::assertSame(LoginStatus::TOTP_ENROL_REQUIRED->value, $e->getCode());
            self::assertSame(self::SECRET, $e->getData()['secret']);
            self::assertStringStartsWith('otpauth://totp/sysPass:ana?secret=' . self::SECRET, $e->getData()['uri']);
        }
    }

    /**
     * Trying again shows the secret already shown, rather than replacing what was scanned.
     */
    public function testTryingAgainShowsTheSameSecret(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(false);
        $this->userTotpService->method('pendingSecret')->willReturn(self::SECRET);
        $this->userTotpService->expects($this->never())->method('startEnrolment');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_ENROL_REQUIRED->value);

        $this->loginTotp->check($this->user(true));
    }

    public function testAWrongEnrolmentCodeIsCountedAndAsksAgain(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(false);
        $this->userTotpService->method('pendingSecret')->willReturn(self::SECRET);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->method('checkPending')->willReturn(null);
        $this->trackService->expects($this->once())->method('add');

        try {
            $this->loginTotp->check($this->user(true));
            self::fail('a wrong code was accepted');
        } catch (AuthException $e) {
            self::assertSame(LoginStatus::TOTP_ENROL_REQUIRED->value, $e->getCode());
            self::assertSame('Wrong two-factor code', $e->getMessage());
            // The QR code has to stay on screen for the next attempt.
            self::assertSame(self::SECRET, $e->getData()['secret']);
        }
    }

    /**
     * The right code is checked, not spent: the enrolment is confirmed only when sign-in completes.
     */
    public function testTheRightEnrolmentCodeConfirmsNothingYet(): void
    {
        $proof = TotpProof::enrolment(42);

        $this->userTotpService->method('isEnabled')->willReturn(false);
        $this->userTotpService->method('pendingSecret')->willReturn(self::SECRET);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->expects($this->once())->method('checkPending')->with(self::USER_ID, '123456')->willReturn($proof);
        $this->userTotpService->expects($this->never())->method('spend');

        self::assertSame($proof, $this->loginTotp->check($this->user(true)));
    }

    /**
     * Completing the sign-in confirms the enrolment and issues the recovery codes it shows.
     */
    public function testCompletingAnEnrolmentIssuesTheRecoveryCodes(): void
    {
        $proof = TotpProof::enrolment(42);

        $this->userTotpService->expects($this->once())->method('spend')->with(self::USER_ID, $proof)->willReturn(true);
        $this->userTotpService
            ->expects($this->once())
            ->method('generateRecoveryCodes')
            ->with(self::USER_ID)
            ->willReturn(['aaaaaa-bbbbbb']);

        self::assertSame(['aaaaaa-bbbbbb'], $this->loginTotp->consume(self::USER_ID, $proof));
    }

    /**
     * A user who already has a second factor is asked for a code, required or not.
     */
    public function testARequiredUserWhoHasOneIsAskedForACode(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->userTotpService->expects($this->never())->method('startEnrolment');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);

        $this->loginTotp->check($this->user(true));
    }

    private function user(bool $totpRequired = false): UserDto
    {
        return new UserDto(id: self::USER_ID, login: 'ana', isTotpRequired: $totpRequired);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->trackService = $this->createMock(TrackService::class);
        $this->trackService
            ->expects($this->atLeastOnce())
            ->method('buildTrackRequest')
            ->with('login')
            ->willReturn(
                new TrackRequest(
                    self::$faker->unixTime(),
                    self::$faker->colorName(),
                    self::$faker->ipv4(),
                    self::$faker->randomNumber(2)
                )
            );

        $this->request = $this->createMock(RequestService::class);
        $this->userTotpService = $this->createMock(UserTotpService::class);

        $this->loginTotp = new LoginTotp(
            $this->application,
            $this->trackService,
            $this->request,
            $this->userTotpService
        );
    }
}
