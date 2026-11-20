<?php
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

declare(strict_types=1);

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserSettingsTotp;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Auth\Dtos\TotpProof;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\User\Dtos\UserDto;
use SP\Tests\Support\IntegrationTestCase;

/**
 * A signed-in user managing their own second factor
 *
 * Every action acts on the user in the session — there is no id to send — and the two that
 * weaken the second factor ask for a current code first, so a session left open, or taken, is
 * not enough to remove it.
 */
#[Group('integration')]
class UserSettingsTotpTest extends IntegrationTestCase
{
    private const VIEWER_ID = 100;
    private const VIEWER_LOGIN = 'ana';

    private UserTotpService|MockObject $userTotpService;
    private bool $required = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userTotpService = $this->createMock(UserTotpService::class);
    }

    /**
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(
            ['id' => self::VIEWER_ID, 'login' => self::VIEWER_LOGIN, 'isTotpRequired' => $this->required]
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function turningItOnAnswersWithTheSecretAndWhatTheAppScans(): void
    {
        $this->userTotpService
            ->expects($this->once())
            ->method('startEnrolment')
            ->with(self::VIEWER_ID)
            ->willReturn('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');

        $json = $this->dispatch('start');

        self::assertSame('OK', $json->status);
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $json->data->secret);
        self::assertStringStartsWith('otpauth://totp/sysPass:ana?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $json->data->uri);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aWrongCodeLeavesItOffAndIssuesNoRecoveryCodes(): void
    {
        $this->userTotpService
            ->expects($this->once())
            ->method('confirmEnrolment')
            ->with(self::VIEWER_ID, '123456')
            ->willReturn(false);
        $this->userTotpService->expects($this->never())->method('generateRecoveryCodes');

        $json = $this->dispatch('confirm', ['code' => '123456']);

        self::assertSame('ERROR', $json->status);
        self::assertSame('Wrong two-factor code', $json->description);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function confirmingTurnsItOnAndHandsOverTheRecoveryCodesOnce(): void
    {
        $this->userTotpService->method('confirmEnrolment')->willReturn(true);
        $this->userTotpService
            ->expects($this->once())
            ->method('generateRecoveryCodes')
            ->with(self::VIEWER_ID)
            ->willReturn(['aaaaaa-bbbbbb', 'cccccc-dddddd']);

        $json = $this->dispatch('confirm', ['code' => '123456']);

        self::assertSame('OK', $json->status);
        self::assertSame(['aaaaaa-bbbbbb', 'cccccc-dddddd'], $json->data->recoveryCodes);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function turningItOffWithoutACodeIsRefused(): void
    {
        $this->userTotpService->expects($this->never())->method('check');
        $this->userTotpService->expects($this->never())->method('disable');

        $json = $this->dispatch('disable');

        self::assertSame('ERROR', $json->status);
        self::assertSame('Wrong two-factor code', $json->description);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function turningItOffWithAWrongCodeIsRefused(): void
    {
        $this->userTotpService->method('check')->willReturn(null);
        $this->userTotpService->expects($this->never())->method('disable');

        self::assertSame('ERROR', $this->dispatch('disable', ['code' => '000000'])->status);
    }

    /**
     * A code another request spent between the check and the spend does not count.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function turningItOffWithACodeAlreadySpentIsRefused(): void
    {
        $this->userTotpService->method('check')->willReturn(TotpProof::step(42));
        $this->userTotpService->method('spend')->willReturn(false);
        $this->userTotpService->expects($this->never())->method('disable');

        self::assertSame('ERROR', $this->dispatch('disable', ['code' => '123456'])->status);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function turningItOffWithACurrentCodeTurnsItOffForTheSignedInUser(): void
    {
        $proof = TotpProof::step(42);

        $this->userTotpService
            ->expects($this->once())
            ->method('check')
            ->with(self::VIEWER_ID, '123456')
            ->willReturn($proof);
        $this->userTotpService
            ->expects($this->once())
            ->method('spend')
            ->with(self::VIEWER_ID, $proof)
            ->willReturn(true);
        $this->userTotpService->expects($this->once())->method('disable')->with(self::VIEWER_ID);

        self::assertSame('OK', $this->dispatch('disable', ['code' => '123456'])->status);
    }

    /**
     * Required by an administrator: turning it off would only send the user through enrolment
     * at the next sign-in, so it is refused with the reason, before any code is spent.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aRequiredSecondFactorCannotBeTurnedOff(): void
    {
        $this->required = true;

        $this->userTotpService->expects($this->never())->method('check');
        $this->userTotpService->expects($this->never())->method('disable');

        $json = $this->dispatch('disable', ['code' => '123456']);

        self::assertSame('ERROR', $json->status);
        self::assertSame('Your administrator requires two-factor sign-in', $json->description);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function newRecoveryCodesNeedACurrentCode(): void
    {
        $this->userTotpService->expects($this->never())->method('generateRecoveryCodes');

        self::assertSame('ERROR', $this->dispatch('recoveryCodes')->status);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function newRecoveryCodesWithACurrentCodeAreHandedOver(): void
    {
        $this->userTotpService->method('check')->willReturn(TotpProof::step(42));
        $this->userTotpService->method('spend')->willReturn(true);
        $this->userTotpService
            ->expects($this->once())
            ->method('generateRecoveryCodes')
            ->with(self::VIEWER_ID)
            ->willReturn(['aaaaaa-bbbbbb']);

        $json = $this->dispatch('recoveryCodes', ['code' => '123456']);

        self::assertSame('OK', $json->status);
        self::assertSame(['aaaaaa-bbbbbb'], $json->data->recoveryCodes);
    }

    /**
     * @param array<string, string> $post
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function dispatch(string $action, array $post = []): object
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'userSettingsTotp/' . $action], $post),
            [UserTotpService::class => $this->userTotpService]
        );

        ob_start();

        try {
            IntegrationTestCase::runApp($container);
        } finally {
            $output = (string)ob_get_clean();
        }

        $json = json_decode($output);

        self::assertIsObject($json, $output);

        return $json;
    }
}
