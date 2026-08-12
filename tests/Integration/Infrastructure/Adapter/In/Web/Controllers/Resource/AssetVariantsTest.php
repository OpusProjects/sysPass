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
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the bundles each asset route can serve. Only the default bundle was exercised.
 *
 * A request may name its own files and directory, which is the branch that reaches the app-path
 * guard, and the JS route additionally serves the application's own bundle rather than the
 * vendor one. Each variant is a separate branch through the controller.
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
    public function aBundleIsServedWithoutRaising(string $route, array $params): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', array_merge(['r' => $route], $params))
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('');
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
                ['f' => urlencode('reset.min.css'), 'b' => urlencode('public/vendor/css')],
            ],
            'scripts named by the request' => [
                'resource/js',
                ['f' => urlencode('app-util.min.js'), 'b' => urlencode('public/js')],
            ],
            'several files named at once' => [
                'resource/js',
                ['f' => urlencode('app-util.min.js,app-main.min.js'), 'b' => urlencode('public/js')],
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
                ['r' => 'resource/css', 'f' => urlencode('passwd'), 'b' => urlencode($base)]
            )
        );

        IntegrationTestCase::runApp($container);

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
