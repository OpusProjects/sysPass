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

namespace SP\Tests\Infrastructure\Adapter\In\Web\Controllers\UserPassReset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\User\Models\User as UserModel;
use SP\Infrastructure\Database\QueryResult;
use SP\Tests\Generators\UserDataGenerator;
use SP\Tests\IntegrationTestCase;

/**
 * Guards the unauthenticated "forgot my password" request. It builds the reset
 * email via UserPassRecover::getMailMessage($hash, $baseUri) — a call that was
 * passing only $hash, throwing ArgumentCountError (an Error, not caught by the
 * controller's catch(Exception)) and 500ing before the email was sent.
 */
#[Group('integration')]
class SaveRequestControllerTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testSaveRequestBuildsTheResetMailWithoutFataling(): void
    {
        $login = 'resetme';
        $email = 'resetme@example.com';

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(
            [
                'login'      => $login,
                'email'      => $email,
                'isDisabled' => false,
                'isLdap'     => false,
            ]
        );

        $this->addDatabaseMapperResolver(UserModel::class, new QueryResult([$userData]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userPassReset/saveRequest'],
                ['login' => $login, 'email' => $email]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputRegex('/"status":"OK","description":"Request sent"/');
    }
}
