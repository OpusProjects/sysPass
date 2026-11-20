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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\User;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\User\Ports\UserTotpService;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\User\Dtos\UserDto;
use SP\Domain\User\Models\User;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

/**
 * An administrator removing a user's second factor, for somebody who has lost both their phone
 * and their recovery codes
 */
#[Group('integration')]
class TotpResetTest extends IntegrationTestCase
{
    private const EDITED_ID = 100;

    private UserTotpService|MockObject $userTotpService;
    private int $userUpdates = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userTotpService = $this->createMock(UserTotpService::class);

        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getOnErrorMessage() === 'Error while updating the user') {
                $this->userUpdates++;
            }

            return new QueryResult([], 1, self::EDITED_ID);
        };
    }

    protected function getUserDataDto(): UserDto
    {
        return parent::getUserDataDto()->mutate(['id' => 1, 'isAdminApp' => true]);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theResetRemovesTheEditedUsersSecondFactor(): void
    {
        $this->userTotpService->expects($this->once())->method('disable')->with(self::EDITED_ID);

        $json = $this->saveEdit(['totp_reset' => 'on']);

        self::assertSame('OK', $json->status, json_encode($json));
        self::assertSame(1, $this->userUpdates);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anOrdinaryEditLeavesItAlone(): void
    {
        $this->userTotpService->expects($this->never())->method('disable');

        self::assertSame('OK', $this->saveEdit()->status);
    }

    /**
     * Refused — on a demo, UserTotp refuses every change — the edit is not half saved: the reset
     * is attempted before the user is written.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aRefusedResetSavesNothing(): void
    {
        $this->userTotpService
            ->expects($this->once())
            ->method('disable')
            ->willThrowException(ServiceException::error('Ey, this is a DEMO!!'));

        $json = $this->saveEdit(['totp_reset' => 'on']);

        self::assertSame('ERROR', $json->status);
        self::assertSame('Ey, this is a DEMO!!', $json->description);
        self::assertSame(0, $this->userUpdates);
    }

    /**
     * The switch is offered only for a user who has a second factor to remove.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function theSwitchIsOfferedOnlyWhereThereIsSomethingToReset(): void
    {
        $this->userTotpService
            ->expects($this->exactly(2))
            ->method('isEnabled')
            ->with(self::EDITED_ID)
            ->willReturnOnConsecutiveCalls(true, false);

        self::assertStringContainsString('name="totp_reset"', $this->editForm());
        self::assertStringNotContainsString('name="totp_reset"', $this->editForm());
    }

    /**
     * @param array<string, string> $extra
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function saveEdit(array $extra = []): object
    {
        $fields = [
            'name' => self::$faker->name(),
            'login' => self::$faker->userName(),
            'email' => self::$faker->email(),
            'usergroup_id' => self::$faker->numberBetween(1, 99),
            'userprofile_id' => self::$faker->numberBetween(1, 99),
        ] + $extra;

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveEdit/' . self::EDITED_ID], $fields),
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

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function editForm(): string
    {
        // The form reads the user through the mapper, which the save's resolver would answer
        // with nothing.
        $this->databaseQueryResolver = null;
        $this->addDatabaseMapperResolver(User::class, new QueryResult([UserDataGenerator::factory()->buildUserData()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/edit/' . self::EDITED_ID]),
            [UserTotpService::class => $this->userTotpService]
        );

        ob_start();

        try {
            IntegrationTestCase::runApp($container);
        } finally {
            $output = (string)ob_get_clean();
        }

        $json = json_decode($output);
        self::assertSame('OK', $json->status ?? null, $output);

        return (string)$json->data->html;
    }
}
