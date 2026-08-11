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
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the status endpoints, which ask GitHub about releases and notices. Neither had tests.
 *
 * The HTTP client is replaced so the assertions are about how a reply is interpreted, and so the
 * suite never reaches the network.
 */
#[Group('integration')]
class StatusTest extends IntegrationTestCase
{
    /** Replaced per test so the suite never reaches the network. */
    private ?ClientInterface $httpClient = null;

    /**
     * A published version newer than the running one is reported as an available update.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUpdateAvailable')]
    public function checkReleaseWithNewerVersion()
    {
        $this->whenTheServiceReplies(
            [
                'tag_name' => 'v99.0.0.99',
                'html_url' => 'https://example.invalid/releases/99',
                'name' => 'sysPass 99',
                'body' => 'Release notes',
                'published_at' => '2024-03-01T00:00:00Z',
            ]
        );

        $this->whenRequesting('status/checkRelease');
    }

    /**
     * A reply that is not a release — GitHub answers errors with a message field — must not be
     * read as an update.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerVersionUnavailable')]
    public function checkReleaseWithErrorReply()
    {
        $this->whenTheServiceReplies(['message' => 'Not Found']);

        $this->whenRequesting('status/checkRelease');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNotices')]
    public function checkNotices()
    {
        $this->whenTheServiceReplies(
            [
                ['title' => 'First notice', 'created_at' => '2024-01-01T00:00:00Z', 'body' => 'Body one'],
                ['title' => 'Second notice', 'created_at' => '2024-02-01T00:00:00Z', 'body' => 'Body two'],
            ]
        );

        $this->whenRequesting('status/checkNotices');
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws Exception
     */
    private function whenTheServiceReplies(array $payload): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('request')->willReturn(
            new Response(200, ['content-type' => 'application/json'], json_encode($payload))
        );

        $this->httpClient = $client;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route]),
            [ClientInterface::class => $this->httpClient]
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerUpdateAvailable(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertSame('v99.0.0.99', $json->data->version);
        self::assertSame('https://example.invalid/releases/99', $json->data->url);
        self::assertSame('sysPass 99', $json->data->title);
    }

    /**
     * GitHub reports failures as a message field. That is not a release, so the endpoint says the
     * version is unavailable rather than treating the reply as an answer.
     */
    private function outputCheckerVersionUnavailable(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Version unavailable', $json->description);
    }

    private function outputCheckerNotices(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertCount(2, $json->data);
        self::assertSame('First notice', $json->data[0]->title);
    }
}
