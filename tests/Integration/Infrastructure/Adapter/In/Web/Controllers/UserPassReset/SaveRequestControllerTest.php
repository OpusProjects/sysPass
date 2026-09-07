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
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Application\Notification\Ports\MailService;
use SP\Domain\Core\Messages\MailMessage;
use SP\Domain\User\Models\User as UserModel;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;

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

    /**
     * The mailed link points where the installation lives, not where the caller said it does.
     *
     * `saveRequestAction()` needs no session, and the base URI came from
     * `UriContextInterface::getWebUri()`, which prefers `Forwarded` / `X-Forwarded-Host`. Nothing
     * in this application calls `setTrustedProxies()`, so those headers are whatever the caller
     * sent — verified against the running instance, where `X-Forwarded-Host: evil.example.com`
     * comes straight back out.
     *
     * So an unauthenticated caller who knew a login and its email address could choose the host in
     * the message the real user then received: a genuine mail, from the real installation, whose
     * link hands the one-time hash to somebody else's server.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function testTheResetLinkIgnoresAForwardedHost(): void
    {
        $login = 'resetme';
        $email = 'resetme@example.com';

        $userData = UserDataGenerator::factory()->buildUserData()->mutate(
            ['login' => $login, 'email' => $email, 'isDisabled' => false, 'isLdap' => false]
        );

        $this->addDatabaseMapperResolver(UserModel::class, new QueryResult([$userData]));

        $sent = null;
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willReturnCallback(
            static function (string $subject, string|array $to, MailMessage $mailMessage) use (&$sent): void {
                $sent = $mailMessage->composeText();
            }
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'userPassReset/saveRequest'],
                ['login' => $login, 'email' => $email],
                [],
                self::CSRF_TOKEN,
                ['HTTP_X_FORWARDED_HOST' => 'evil.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https']
            ),
            [MailService::class => $mailService]
        );

        IntegrationTestCase::runApp($container);

        self::assertNotNull($sent, 'the reset mail has to be sent for this to say anything');
        self::assertStringNotContainsString('evil.example.com', $sent);
        self::assertStringContainsString('localhost', $sent);

        $this->expectOutputRegex('/"status":"OK","description":"Request sent"/');
    }
}
