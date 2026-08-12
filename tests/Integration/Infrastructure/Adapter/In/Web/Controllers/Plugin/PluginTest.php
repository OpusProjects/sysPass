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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Plugin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Plugin\Models\Plugin;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the plugin management endpoints. Only the per-plugin ACL had a test.
 *
 * Enabling and disabling a plugin changes what code the application loads on later requests, so
 * these are worth having pinned even though the endpoints are small.
 */
#[Group('integration')]
class PluginTest extends IntegrationTestCase
{
    private const PLUGIN_NAME = 'Authenticator';

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerNamedPlugin')]
    public function view()
    {
        $this->givenAPlugin();

        $this->whenRequesting('plugin/view/100');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerSearch')]
    public function search()
    {
        $this->addDatabaseMapperResolver(
            Plugin::class,
            QueryResult::withTotalNumRows([$this->buildPlugin(), $this->buildPlugin()], 2)
        );

        $this->whenRequesting('plugin/search');
    }

    /**
     * Enabling a plugin is what makes the application load it on later requests.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function enable()
    {
        $this->givenAPlugin();

        $this->whenRequesting('plugin/enable/100');

        $this->expectOutputString('{"status":"OK","description":"Plugin enabled","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function disable()
    {
        $this->givenAPlugin();

        $this->whenRequesting('plugin/disable/100');

        $this->expectOutputString('{"status":"OK","description":"Plugin disabled","data":null}');
    }

    /**
     * Resetting discards whatever the plugin had stored.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function reset()
    {
        $this->givenAPlugin();

        $this->whenRequesting('plugin/reset/100');

        $this->expectOutputString('{"status":"OK","description":"Plugin reset","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingle()
    {
        $this->givenAPlugin();

        $this->whenRequesting('plugin/delete/100');

        $this->expectOutputString('{"status":"OK","description":"Plugin deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteWithoutSelection()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'plugin/delete', 'items' => []])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"No items selected","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiple()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 2, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'plugin/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Plugins deleted","data":null}');
    }

    private function buildPlugin(): Plugin
    {
        return new Plugin(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::PLUGIN_NAME,
                'enabled' => true,
                'available' => true,
                'versionLevel' => '1.0',
            ]
        );
    }

    private function givenAPlugin(): void
    {
        $this->addDatabaseMapperResolver(Plugin::class, new QueryResult([$this->buildPlugin()]));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    private function whenRequesting(string $route): void
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => $route])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * Viewing a plugin renders the one that was asked for, so its name reaches the page.
     */
    private function outputCheckerNamedPlugin(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertStringContainsString(self::PLUGIN_NAME, $json->data->html);
    }

    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblPlugins"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }
}
