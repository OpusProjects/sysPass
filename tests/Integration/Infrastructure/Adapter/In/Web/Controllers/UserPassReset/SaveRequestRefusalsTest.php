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
use SP\Application\Notification\Ports\MailService;
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
    #[BodyChecker('outputCheckerIndistinguishable')]
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
    #[BodyChecker('outputCheckerIndistinguishable')]
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
    #[BodyChecker('outputCheckerIndistinguishable')]
    public function anLdapAccountIsRefused()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL, 'isLdap' => true]);

        $this->whenRequesting('someone', self::REGISTERED_EMAIL);
    }

    /**
     * A login nobody has is answered exactly like one that exists.
     *
     * This is the half that was still open. The disabled and LDAP refusals had already been
     * collapsed into a single message for this reason; an unknown login still answered "User not
     * found" while a known one with the wrong address answered "Wrong data", so the first question
     * anybody would ask — does this login exist — was answerable by anyone, unauthenticated.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIndistinguishable')]
    public function aLoginThatDoesNotExistIsAnsweredLikeOneThatDoes()
    {
        $this->givenNoSuchUser();

        $this->whenRequesting('nobody', self::REGISTERED_EMAIL);
    }

    /**
     * And so is a request that actually works, or the answer would separate the successes from
     * everything else instead — which tells a caller just as much.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIndistinguishable')]
    public function aRequestThatSucceedsIsAnsweredTheSameWay()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL]);

        $this->whenRequesting('someone', self::REGISTERED_EMAIL);
    }

    /**
     * The request that works still sends the mail.
     *
     * Every outcome now answers the same string, which is the point — but a response that says
     * "Request sent" whatever happened is also exactly what an endpoint that had quietly stopped
     * sending anything would produce. Nothing else here would notice, because the failure path is
     * deliberately swallowed. So this asserts the mail itself, at the address on the account.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerIndistinguishable')]
    public function aRequestThatSucceedsStillSendsTheMail()
    {
        $this->givenAUser(['email' => self::REGISTERED_EMAIL]);

        $sentTo = [];

        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willReturnCallback(
            static function (string $subject, string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            }
        );

        $this->whenRequesting('someone', self::REGISTERED_EMAIL, [MailService::class => $mailService]);

        self::assertSame([self::REGISTERED_EMAIL], $sentTo, 'the recovery mail must still go out');
    }

    /**
     * No row for the login, which is what the service turns into its "User not found".
     */
    private function givenNoSuchUser(): void
    {
        $this->addDatabaseMapperResolver(User::class, new QueryResult([]));
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
    /**
     * @param array<string, mixed> $definitionsOverride
     */
    private function whenRequesting(string $login, string $email, array $definitionsOverride = []): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userPassReset/saveRequest'],
                ['login' => $login, 'email' => $email]
            ),
            $definitionsOverride
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Every outcome is asserted against the same string, deliberately. That is the whole point of
     * the change these pin: an unauthenticated caller must not be able to tell a login that does
     * not exist from one that does, nor either from a request that actually sent a mail.
     */
    private function outputCheckerIndistinguishable(string $output): void
    {
        $json = json_decode($output);

        self::assertSame('OK', $json->status);
        self::assertSame('Request sent', $json->description);
    }
}
