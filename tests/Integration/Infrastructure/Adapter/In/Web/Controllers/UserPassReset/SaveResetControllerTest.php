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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\User\Ports\UserPassRecoverService;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\User\Models\User;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the endpoint that sets a new password from a recovery link. It had no tests.
 *
 * This is an unauthenticated endpoint that changes a password, so what it refuses matters as
 * much as what it accepts.
 */
#[Group('integration')]
class SaveResetControllerTest extends IntegrationTestCase
{
    private const HASH = 'a-recovery-hash';

    /**
     * A valid reset sets the password and consumes the recovery link, so the same link cannot be
     * used a second time. The consumption is asserted on the service call, since the response
     * looks the same whether or not it happened.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aValidResetUpdatesThePasswordAndConsumesTheLink()
    {
        $this->addDatabaseMapperResolver(User::class, new QueryResult([new User(['id' => 5, 'login' => 'someone'])]));

        $recover = $this->createMock(UserPassRecoverService::class);
        $recover->method('getUserIdForHash')->willReturn(5);
        $recover->expects(self::once())->method('toggleUsedByHash')->with(self::HASH);

        $this->whenResetting(
            ['password' => 'a-new-password', 'password_repeat' => 'a-new-password', 'hash' => self::HASH],
            [UserPassRecoverService::class => $recover]
        );

        $this->expectOutputString('{"status":"OK","description":"Password updated","data":null}');
    }

    /**
     * A blank password is refused rather than setting an empty one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aBlankPasswordIsRefused()
    {
        $this->whenResetting(['password' => '', 'password_repeat' => '', 'hash' => self::HASH]);

        $this->expectOutputString(
            '{"status":"ERROR","description":"Password cannot be blank","data":null}'
        );
    }

    /**
     * The confirmation has to match, so a typo does not silently set a password the user does
     * not know.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function aMismatchedConfirmationIsRefused()
    {
        $this->whenResetting(
            ['password' => 'a-new-password', 'password_repeat' => 'a-different-one', 'hash' => self::HASH]
        );

        $this->expectOutputString(
            '{"status":"ERROR","description":"Passwords do not match","data":null}'
        );
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $definitions
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenResetting(array $fields, array $definitions = []): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'userPassReset/saveReset'], $fields),
            $definitions
        );

        IntegrationTestCase::runApp($container);
    }
}
