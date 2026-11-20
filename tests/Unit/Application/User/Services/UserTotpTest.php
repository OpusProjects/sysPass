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

namespace SP\Tests\Unit\Application\User\Services;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use SP\Application\User\Services\UserTotp;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\User\Ports\UserTotpRecoveryRepository;
use SP\Domain\User\Ports\UserTotpRepository;
use SP\Tests\Support\UnitaryTestCase;

/**
 * The guards of the second-factor service that a real database is not needed to show
 */
#[Group('unitary')]
#[AllowMockObjectsWithoutExpectations]
class UserTotpTest extends UnitaryTestCase
{
    private UserTotpRepository|MockObject         $userTotpRepository;
    private UserTotpRecoveryRepository|MockObject $userTotpRecoveryRepository;
    private UserTotp                              $userTotp;

    /**
     * @return array<string, array{callable(UserTotp): mixed}>
     */
    public static function changeProvider(): array
    {
        return [
            'turning it on' => [static fn(UserTotp $s) => $s->startEnrolment(1)],
            'turning it off' => [static fn(UserTotp $s) => $s->disable(1)],
            'new recovery codes' => [static fn(UserTotp $s) => $s->generateRecoveryCodes(1)],
        ];
    }

    /**
     * A demo publishes its administrator's credentials: a visitor turning a second factor on for
     * that account would lock out every other one.
     */
    #[DataProvider('changeProvider')]
    public function testNothingChangesOnADemo(callable $change): void
    {
        $this->config->getConfigData()->setDemoEnabled(true);

        $this->userTotpRepository->expects($this->never())->method('replace');
        $this->userTotpRepository->expects($this->never())->method('deleteByUserId');
        $this->userTotpRecoveryRepository->expects($this->never())->method('replace');
        $this->userTotpRecoveryRepository->expects($this->never())->method('deleteByUserId');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Ey, this is a DEMO!!');

        $change($this->userTotp);
    }

    /**
     * Without a confirmed second factor there is nothing for recovery codes to stand in for.
     */
    public function testNoRecoveryCodesWithoutASecondFactor(): void
    {
        $this->userTotpRepository->method('getByUserId')->willReturn(new QueryResult([]));
        $this->userTotpRecoveryRepository->expects($this->never())->method('replace');

        $this->expectException(ServiceException::class);

        $this->userTotp->generateRecoveryCodes(1);
    }

    /**
     * A user without a second factor has nothing to check a code against, recovery or not.
     */
    public function testNoCodeIsAcceptedWithoutASecondFactor(): void
    {
        $this->userTotpRepository->method('getByUserId')->willReturn(new QueryResult([]));
        $this->userTotpRecoveryRepository->expects($this->never())->method('countUnused');

        self::assertNull($this->userTotp->check(1, 'abcdef-ghij23'));
        self::assertNull($this->userTotp->check(1, '123456'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->userTotpRepository = $this->createMock(UserTotpRepository::class);
        $this->userTotpRecoveryRepository = $this->createMock(UserTotpRecoveryRepository::class);

        $this->userTotp = new UserTotp(
            $this->application,
            $this->userTotpRepository,
            $this->userTotpRecoveryRepository,
            $this->createStub(CryptInterface::class)
        );
    }
}
