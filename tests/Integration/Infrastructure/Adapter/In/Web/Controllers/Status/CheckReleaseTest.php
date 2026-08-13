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
use Psr\Http\Message\ResponseInterface;
use SP\Domain\Core\AppInfoInterface;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * The update check asks a third-party service what the latest release is and decides whether to
 * tell the administrator about it.
 *
 * The decision is the part worth pinning: an instance must only be told about a release that is
 * actually newer than the one it is running. Getting the comparison backwards would nag every
 * administrator on every visit about a version they already have — or, worse, stay silent when a
 * genuine update exists.
 */
#[Group('integration')]
class CheckReleaseTest extends IntegrationTestCase
{
    private ?ResponseInterface $answer = null;

    /**
     * A release newer than the running one is offered, with everything the page shows about it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUpdateOffered')]
    public function aNewerReleaseIsOffered()
    {
        $this->answer = $this->releaseAnswer($this->tagFor(patchOffset: 1));

        $this->whenCheckingForARelease();
    }

    /**
     * The release the instance is already running is not offered as an update.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNothingToOffer')]
    public function theReleaseAlreadyRunningIsNotOffered()
    {
        $this->answer = $this->releaseAnswer($this->tagFor(patchOffset: 0));

        $this->whenCheckingForARelease();
    }

    /**
     * Nor is an older one — a service that has been rolled back, or a stale cache in front of it,
     * must not turn into an update prompt.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNothingToOffer')]
    public function anOlderReleaseIsNotOffered()
    {
        $this->answer = $this->releaseAnswer('v3.2.11.19012201');

        $this->whenCheckingForARelease();
    }

    /**
     * A tag that is not a version at all is not parsed into one. The tag is whatever somebody typed
     * when they cut the release.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUnavailable')]
    public function aTagThatIsNotAVersionIsRefused()
    {
        $this->answer = $this->releaseAnswer('nightly');

        $this->whenCheckingForARelease();
    }

    /**
     * The service answers its own errors as a JSON object with a message — rate limiting, most
     * often. That is not a release, so nothing is offered.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerUnavailable')]
    public function anErrorMessageFromTheServiceIsNotARelease()
    {
        $this->answer = new Response(
            200,
            ['content-type' => 'application/json'],
            json_encode(['message' => 'API rate limit exceeded'], JSON_THROW_ON_ERROR)
        );

        $this->whenCheckingForARelease();
    }

    /**
     * A tag for this instance's own version, optionally bumped — derived from the constants rather
     * than written out, so bumping the application's version does not silently invert what these
     * tests are asserting.
     */
    private function tagFor(int $patchOffset): string
    {
        [$major, $minor, $patch] = AppInfoInterface::APP_VERSION;

        return sprintf('v%d.%d.%d.%d', $major, $minor, $patch + $patchOffset, AppInfoInterface::APP_BUILD);
    }

    private function releaseAnswer(string $tag): ResponseInterface
    {
        return new Response(
            200,
            ['content-type' => 'application/json'],
            json_encode(
                [
                    'tag_name' => $tag,
                    'html_url' => 'https://example.invalid/releases/' . $tag,
                    'name' => 'Release ' . $tag,
                    'body' => 'What changed',
                    'published_at' => '2024-01-02',
                ],
                JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenCheckingForARelease(): void
    {
        // Answering in the real client's place: the test must not depend on the network being
        // there, nor on what that service happens to be publishing today.
        $client = self::createStub(ClientInterface::class);
        $client->method('request')->willReturn($this->answer);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'status/checkRelease']),
            [ClientInterface::class => $client]
        );

        IntegrationTestCase::runApp($container);
    }

    private function outputCheckerUpdateOffered(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertSame($this->tagFor(patchOffset: 1), $json->data->version);
        self::assertNotEmpty($json->data->url);
        self::assertNotEmpty($json->data->title);
        self::assertNotEmpty($json->data->description);
        self::assertSame('2024-01-02', $json->data->date);
    }

    /**
     * Nothing to offer is a success with no release in it, not an error — there is nothing wrong
     * with being up to date.
     */
    private function outputCheckerNothingToOffer(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertEmpty($json->data);
    }

    private function outputCheckerUnavailable(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('ERROR', $json->status);
        self::assertSame('Version unavailable', $json->description);
    }
}
