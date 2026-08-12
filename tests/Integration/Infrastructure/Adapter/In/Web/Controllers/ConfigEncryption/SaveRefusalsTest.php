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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ConfigEncryption;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Config\Ports\ConfigFileService;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\Core\Ports\AppLockHandler;
use SP\Domain\User\Dtos\UserDto;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\InjectConfigParam;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Changing the master password re-encrypts every account in the installation, so the endpoint is
 * mostly guards. The existing test covers the change going through; none of the guards were covered.
 *
 * The two that matter are the current master password being right — it is what the accounts are
 * decrypted with before being re-encrypted — and the instance being in maintenance mode: another
 * session writing an account halfway through would leave it encrypted under the old key with no way
 * back.
 */
#[Group('integration')]
#[InjectVault]
class SaveRefusalsTest extends IntegrationTestCase
{
    private const CURRENT = 'a_password';
    private const NEW = 'a_new_password';

    /** The stored hash of self::CURRENT, as the installation holds it. */
    private const CURRENT_HASH = '$2y$10$6imglyA01feP1AnEmlAgUeQPa7vysHeKQLz0MDA1Zf7iG6ep.7PaC';

    /** The application lock is held by the session making the change. */
    private const LOCK_OWNER_ID = 100;

    /**
     * The maintenance gate only lets through the session that holds the lock, so the signed-in user
     * has to be its owner.
     *
     * @throws SPException
     */
    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['id' => self::LOCK_OWNER_ID]);
    }

    /**
     * Nothing typed is refused before anything else is looked at.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotEntered')]
    public function anEmptyFormIsRefused()
    {
        $this->whenSaving([]);
    }

    /**
     * A new password with no current one beside it is the same case: both are needed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotEntered')]
    public function aNewPasswordWithoutTheCurrentOneIsRefused()
    {
        $this->whenSaving(['new_masterpass' => self::NEW, 'new_masterpass_repeat' => self::NEW]);
    }

    /**
     * The confirmation has to be given. Re-encrypting every account is not something to do by
     * submitting a form by accident.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotConfirmed')]
    public function anUnconfirmedChangeIsRefused()
    {
        $this->whenSaving($this->form(['confirm_masterpass_change' => 'false']));
    }

    /**
     * Changing it to what it already is would re-encrypt everything for nothing.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSame')]
    public function changingItToTheSamePasswordIsRefused()
    {
        $this->whenSaving($this->form(['new_masterpass' => self::CURRENT, 'new_masterpass_repeat' => self::CURRENT]));
    }

    /**
     * The repeat has to match, or everything would be re-encrypted under a password nobody knows.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDoNotMatch')]
    public function aMistypedRepeatIsRefused()
    {
        $this->whenSaving($this->form(['new_masterpass_repeat' => 'not-the-same']));
    }

    /**
     * The current master password has to be the real one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWrongCurrent')]
    #[InjectConfigParam(['masterPwd' => self::CURRENT_HASH])]
    public function aWrongCurrentPasswordIsRefused()
    {
        $this->whenSaving($this->form(['current_masterpass' => 'not-the-current-one']));
    }

    /**
     * And the instance has to be in maintenance mode: another session writing an account halfway
     * through the re-encryption would leave it encrypted under the old key with no way back.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerMaintenance')]
    #[InjectConfigParam(['masterPwd' => self::CURRENT_HASH])]
    public function aChangeOutsideMaintenanceModeIsRefused()
    {
        $this->whenSaving($this->form(), maintenance: false);
    }

    /**
     * A demo instance refuses it outright.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerDemo')]
    #[InjectConfigParam(['masterPwd' => self::CURRENT_HASH])]
    public function aDemoInstanceRefusesIt()
    {
        $this->whenSaving($this->form(), demo: true);
    }

    /**
     * The form as it is submitted when everything is right.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge(
            [
                'current_masterpass' => self::CURRENT,
                'new_masterpass' => self::NEW,
                'new_masterpass_repeat' => self::NEW,
                'confirm_masterpass_change' => 'true',
            ],
            $overrides
        );
    }

    /**
     * @param array<string, string> $fields
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenSaving(array $fields, bool $maintenance = true, bool $demo = false): void
    {
        $configData = array_merge(
            $this->getConfigData(),
            ['isMaintenance' => $maintenance, 'isDemoEnabled' => $demo]
        );

        $configFileService = self::createStub(ConfigFileService::class);
        $configFileService
            ->method('getConfigData')
            ->willReturn(self::createConfiguredStub(ConfigDataInterface::class, $configData));

        // In maintenance mode the request is only let through when it is an ajax call from the
        // session holding the application lock — that is how the administrator making the change
        // still reaches the endpoint while everybody else is turned away.
        $appLockHandler = self::createStub(AppLockHandler::class);
        $appLockHandler->method('getLock')->willReturn(self::LOCK_OWNER_ID);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'configEncryption/save'],
                array_merge($fields, ['isAjax' => 1])
            ),
            [ConfigFileService::class => $configFileService, AppLockHandler::class => $appLockHandler]
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerNotEntered(string $output): void
    {
        self::assertSame('Master password not entered', json_decode($output)->description);
    }

    private function outputCheckerNotConfirmed(string $output): void
    {
        self::assertSame('The password update must be confirmed', json_decode($output)->description);
    }

    private function outputCheckerSame(string $output): void
    {
        self::assertSame('Passwords are the same', json_decode($output)->description);
    }

    private function outputCheckerDoNotMatch(string $output): void
    {
        self::assertSame('Master passwords do not match', json_decode($output)->description);
    }

    private function outputCheckerWrongCurrent(string $output): void
    {
        self::assertSame('The current master password does not match', json_decode($output)->description);
    }

    private function outputCheckerMaintenance(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('WARNING', $json->status);
        self::assertSame('Maintenance mode not enabled', $json->description);
    }

    private function outputCheckerDemo(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('WARNING', $json->status);
        self::assertSame('Ey, this is a DEMO!!', $json->description);
    }
}
