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
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\User\Models\User;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers what the password-recovery request refuses.
 *
 * The endpoint is unauthenticated and sends mail to an address the caller supplies alongside a
 * login, so the checks are what stop it becoming a way to send mail to arbitrary addresses, or
 * to reset an account that is not supposed to be reset here.
 */
#[Group('integration')]
class SaveRequestRefusalsTest extends IntegrationTestCase
{
    private const REGISTERED_EMAIL = 'someone@example.invalid';

    /**
     * The address has to be the one on the account. Without this the endpoint would mail a
     * recovery link to whatever address the caller typed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerWrongData')]
    public function anAddressThatIsNotTheAccountsIsRefused()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL]);

        $this->whenRequesting('someone', 'attacker@example.invalid');
    }

    /**
     * A disabled account cannot be recovered into.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerContactAdministrator')]
    public function aDisabledAccountIsRefused()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL, 'isDisabled' => true]);

        $this->whenRequesting('someone', self::REGISTERED_EMAIL);
    }

    /**
     * Nor can one whose password lives in the directory server — resetting it here would change
     * nothing the user signs in with.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerContactAdministrator')]
    public function anLdapAccountIsRefused()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL, 'isLdap' => true]);

        $this->whenRequesting('someone', self::REGISTERED_EMAIL);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function givenAUser(array $properties): void
    {
        $this->addDatabaseMapperResolver(
            User::class,
            new QueryResult([new User(array_merge(['id' => 5, 'login' => 'someone'], $properties))])
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $login, string $email): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userPassReset/saveRequest'],
                ['login' => $login, 'email' => $email]
            )
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerWrongData(string $output): void
    {
        $json = json_decode($output);

        self::assertNotEquals('OK', $json->status);
        self::assertSame('Wrong data', $json->description);
    }

    /**
     * Both refusals answer identically. The reply does not say which of the two applied, so an
     * unauthenticated caller learns nothing about the account from asking — which is why the
     * assertion is on the exact message rather than on it merely failing.
     */
    private function outputCheckerContactAdministrator(string $output): void
    {
        $json = json_decode($output);

        self::assertNotEquals('OK', $json->status);
        self::assertSame('Unable to reset the password', $json->description);
    }
}
