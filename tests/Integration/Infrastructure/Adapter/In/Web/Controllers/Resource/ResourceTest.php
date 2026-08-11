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
use SP\Infrastructure\Http\Services\Request;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the CSS and JS asset routes. Neither had tests.
 *
 * These are among the few controllers that run before the install and session checks, so they
 * answer on a request that has not been through any of that.
 *
 * The served bytes are not asserted here: the minifier writes them straight to the shared
 * response rather than through the body hook this suite observes, so the assertions below are
 * that each route dispatches cleanly, plus full cover of the guard that decides which directory
 * may be served from.
 */
#[Group('integration')]
class ResourceTest extends IntegrationTestCase
{
    /**
     * Dispatching the asset routes raises nothing: a failure anywhere in the chain would be
     * rendered as an error page by the dispatcher, so an empty response is the pass condition.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[DataProvider('assetRouteProvider')]
    public function assetRouteDispatchesCleanly(string $route)
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('');
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function assetRouteProvider(): array
    {
        return [['resource/css'], ['resource/js']];
    }

    /**
     * The directory to serve from arrives in the query string, so it is resolved through the
     * app-path guard. Anything landing outside the application resolves to nothing, and the
     * controller then has no directory to read from.
     */
    #[Test]
    #[DataProvider('pathOutsideTheApplicationProvider')]
    public function aBaseOutsideTheApplicationResolvesToNothing(string $path): void
    {
        self::assertSame('', Request::getSecureAppPath($path));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function pathOutsideTheApplicationProvider(): array
    {
        return [
            ['/etc'],
            ['/etc/passwd'],
            ['../../../../etc'],
            ['public/../../etc'],
            ['does/not/exist'],
        ];
    }

    /**
     * A directory inside the application still resolves, so the guard is refusing the traversals
     * specifically rather than refusing everything. ('/' is not a traversal here: it resolves to
     * the application root, which is inside the tree.)
     */
    #[Test]
    public function aBaseInsideTheApplicationResolves(): void
    {
        $resolved = Request::getSecureAppPath('public/vendor/css');

        self::assertNotSame('', $resolved);
        self::assertStringStartsWith(APP_ROOT, $resolved);
    }

    /**
     * A second base may be given, and only the directories on the allow-list are accepted as
     * one — otherwise the guard would be a way to pick any root.
     */
    #[Test]
    public function anUnlistedSecondBaseIsRefused(): void
    {
        self::assertSame('', Request::getSecureAppPath('css', '/tmp'));
    }
}
