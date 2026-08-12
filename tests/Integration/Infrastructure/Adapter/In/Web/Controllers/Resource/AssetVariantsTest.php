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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Crypt\Hash;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the bundles each asset route can serve.
 *
 * These routes verify a signed URI before doing anything, so a request has to carry the
 * signature to reach the controller at all. An unsigned one dies in the base class, and because
 * these actions answer with a callback that refusal writes no body — so an unsigned request is
 * indistinguishable from a served bundle unless the assertion reads the content.
 */
#[Group('integration')]
class AssetVariantsTest extends IntegrationTestCase
{
    /**
     * Each bundle a route can be asked for is served without raising. A failure anywhere in the
     * chain would be rendered as an error page by the dispatcher, so an empty response is the
     * pass condition.
     *
     * @param array<string, string|int> $params
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('bundleProvider')]
    #[BodyChecker('outputCheckerBundle')]
    public function aBundleIsConcatenatedAndServed(string $route, array $params): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', $this->sign(['r' => $route] + $params))
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A request that is not signed never reaches the controller. Without this the signature
     * could be dropped from the tests above and they would still pass, because the refusal
     * produces no body at all.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function anUnsignedRequestIsRefusedBeforeTheBundleIsBuilt(): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'resource/css'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('');
    }

    /**
     * Mirrors Uri::getUriSigned(), which the request verifies against: `key=urlencode(value)`
     * joined by '&', keyed by the configured salt.
     *
     * @param array<string, string|int> $params
     *
     * @return array<string, string|int>
     */
    private function sign(array $params): array
    {
        $uri = implode(
            '&',
            array_map(
                static fn($name, $value) => sprintf('%s=%s', $name, urlencode((string)$value)),
                array_keys($params),
                $params
            )
        );

        return $params + ['h' => Hash::signMessage($uri, $this->passwordSalt)];
    }

    /**
     * The concatenator prefixes each joined file with a comment naming it, so a served bundle
     * says what it is made of — which is what tells it apart from an empty response.
     */
    private function outputCheckerBundle(string $output): void
    {
        self::assertNotEmpty($output);
        self::assertMatchesRegularExpression('/\.(css|js)/', $output);
    }

    /**
     * @return array<string, array{string, array<string, string|int>}>
     */
    public static function bundleProvider(): array
    {
        return [
            'the default stylesheet bundle' => ['resource/css', []],
            'the default script bundle' => ['resource/js', []],
            'the application script bundle' => ['resource/js', ['g' => 1]],
            'stylesheets named by the request' => [
                'resource/css',
                ['f' => 'reset.min.css', 'b' => 'public/vendor/css'],
            ],
            'scripts named by the request' => [
                'resource/js',
                ['f' => 'app-util.min.js', 'b' => 'public/js'],
            ],
            'several files named at once' => [
                'resource/js',
                ['f' => 'app-util.min.js,app-main.min.js', 'b' => 'public/js'],
            ],
        ];
    }

    /**
     * A directory outside the application resolves to nothing, so the request falls through to
     * serving no file rather than reading from wherever it pointed.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('traversalProvider')]
    public function aDirectoryOutsideTheApplicationServesNothing(string $base): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                $this->sign(['r' => 'resource/css', 'f' => 'passwd', 'b' => $base])
            )
        );

        IntegrationTestCase::runApp($container);

        // The request is signed, so it reaches the controller; the directory resolves to nothing
        // and there is no file to serve.
        $this->expectOutputString('');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function traversalProvider(): array
    {
        return [
            'an absolute path' => ['/etc'],
            'a traversal out of the tree' => ['../../../../etc'],
            'a traversal through the tree' => ['public/../../etc'],
        ];
    }
}
