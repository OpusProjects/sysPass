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
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\Auth\Services\LoginTotp;
use SP\Application\Security\Ports\TrackService;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\Auth\Services\LoginStatus;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Http\Ports\RequestService;
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

    private TrackService|MockObject    $trackService;
    private RequestService|MockObject  $request;
    private UserTotpService|MockObject $userTotpService;
    private LoginTotp                  $loginTotp;

    public function testAUserWithoutASecondFactorIsNotAskedForACode(): void
    {
        $this->userTotpService->expects($this->atLeastOnce())->method('isEnabled')->with(self::USER_ID)->willReturn(false);
        $this->request->expects($this->never())->method('analyzeString');

        self::assertNull($this->loginTotp->check(self::USER_ID));
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

        $this->loginTotp->check(self::USER_ID);
    }

    public function testAWrongCodeIsRefusedAndCounted(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->expects($this->atLeastOnce())->method('verify')->with(self::USER_ID, '123456')->willReturn(null);
        $this->trackService->expects($this->once())->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);
        $this->expectExceptionMessage('Wrong two-factor code');

        $this->loginTotp->check(self::USER_ID);
    }

    public function testTheRightCodeAnswersWithItsStepAndSpendsNothing(): void
    {
        $this->userTotpService->method('isEnabled')->willReturn(true);
        $this->request->expects($this->atLeastOnce())->method('analyzeString')->with('totp')->willReturn('123456');
        $this->userTotpService->method('verify')->willReturn(42);
        $this->userTotpService->expects($this->never())->method('consume');
        $this->trackService->expects($this->never())->method('add');

        self::assertSame(42, $this->loginTotp->check(self::USER_ID));
    }

    /**
     * Two sign-ins with the same code both pass check(); the one that loses the race to spend
     * it is refused like a wrong code.
     */
    public function testACodeAnotherSignInSpentFirstIsRefusedAndCounted(): void
    {
        $this->userTotpService->expects($this->atLeastOnce())->method('consume')->with(self::USER_ID, 42)->willReturn(false);
        $this->trackService->expects($this->once())->method('add');

        $this->expectException(AuthException::class);
        $this->expectExceptionCode(LoginStatus::TOTP_REQUIRED->value);

        $this->loginTotp->consume(self::USER_ID, 42);
    }

    public function testSpendingAFreshStepPasses(): void
    {
        $this->userTotpService->expects($this->once())->method('consume')->with(self::USER_ID, 42)->willReturn(true);
        $this->trackService->expects($this->never())->method('add');

        $this->loginTotp->consume(self::USER_ID, 42);
    }

    /**
     * A storage failure is an internal error, never a pass.
     */
    public function testAFailureReadingTheSecretRefusesTheSignIn(): void
    {
        $this->userTotpService->method('isEnabled')->willThrowException(ServiceException::error('boom'));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Internal error');

        $this->loginTotp->check(self::USER_ID);
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
