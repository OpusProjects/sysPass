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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Status;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Throwable;

/**
 * The notices check is the one thing the application fetches from the internet at an
 * administrator's request, and everything it does with the answer is guesswork about a remote
 * service it does not control.
 *
 * So what matters is that a malformed, empty or unreachable answer leaves the page working — an
 * administrator opening their own instance should not get an error page because somebody else's
 * service is down — while a good answer actually reaches the page.
 */
#[Group('integration')]
class CheckNoticesTest extends IntegrationTestCase
{
    private ResponseInterface|Throwable|null $answer = null;

    /**
     * A well-formed answer reaches the page, with each notice's title, date and text.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNoticesShown')]
    public function theNoticesAreHandedToThePage()
    {
        $this->answer = $this->jsonAnswer(
            [
                ['title' => 'A first notice', 'created_at' => '2024-01-02', 'body' => 'Something happened'],
                ['title' => 'A second notice', 'created_at' => '2024-02-03', 'body' => 'Something else'],
            ]
        );

        $this->whenCheckingForNotices();
    }

    /**
     * An answer that is not JSON is not parsed as if it were. The URL is a third-party service, and
     * an error page or a redirect from it is a perfectly likely reply.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotAvailable')]
    public function anAnswerThatIsNotJsonIsRefused()
    {
        $this->answer = new Response(200, ['content-type' => 'text/html'], '<html>not for you</html>');

        $this->whenCheckingForNotices();
    }

    /**
     * Nor is an answer that came back with a failure status.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotAvailable')]
    public function anAnswerWithAFailureStatusIsRefused()
    {
        $this->answer = new Response(503, ['content-type' => 'application/json'], '[]');

        $this->whenCheckingForNotices();
    }

    /**
     * The service answers its own errors as a JSON object carrying a message — rate limiting, most
     * often. That is not a list of notices, so it is not shown as one; the message is logged for
     * the operator instead of being put on the page.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotAvailable')]
    public function anErrorMessageFromTheServiceIsNotShownAsANotice()
    {
        $this->answer = $this->jsonAnswer(['message' => 'API rate limit exceeded']);

        $this->whenCheckingForNotices();
    }

    /**
     * And a service that cannot be reached at all answers as unavailable rather than letting the
     * transport failure out — an instance with no outbound network must still work.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerAnyError')]
    public function aServiceThatCannotBeReachedDoesNotBreakThePage()
    {
        $this->answer = new ConnectException('Could not resolve host', new Request('GET', 'https://example.invalid'));

        $this->whenCheckingForNotices();
    }

    /**
     * @param array<mixed> $payload
     */
    private function jsonAnswer(array $payload): ResponseInterface
    {
        return new Response(200, ['content-type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenCheckingForNotices(): void
    {
        // The real client would call out to a third-party service; this answers in its place, so
        // the test never depends on the network being there or on what that service says today.
        $client = self::createStub(ClientInterface::class);

        if ($this->answer instanceof Throwable) {
            $client->method('request')->willThrowException($this->answer);
        } else {
            $client->method('request')->willReturn($this->answer);
        }

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'status/checkNotices']),
            [ClientInterface::class => $client]
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerNoticesShown(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertCount(2, $json->data);
        self::assertSame('A first notice', $json->data[0]->title);
        self::assertSame('2024-01-02', $json->data[0]->date);
        self::assertSame('Something happened', $json->data[0]->text);
    }

    private function outputCheckerNotAvailable(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Notifications not available', $json->description);
    }

    /**
     * A transport failure reports whatever the client said went wrong, rather than the page
     * breaking on it.
     */
    private function outputCheckerAnyError(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertNotEmpty($json->description);
    }
}
