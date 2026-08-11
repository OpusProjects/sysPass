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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\ItemPreset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\ItemPreset\Models\ItemPreset;
use SP\Domain\ItemPreset\Models\SessionTimeout;
use SP\Domain\ItemPreset\Ports\ItemPresetInterface;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class ItemPresetTest
 */
#[Group('integration')]
class ItemPresetTest extends IntegrationTestCase
{
    /**
     * The create form is built for a preset type passed on the route.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'itemPreset/create/' . ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT]
            )
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * A stored preset keeps its payload in a serialized blob, so editing has to deserialize it
     * to build the type-specific view.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(ItemPreset::class, new QueryResult([$this->buildPreset()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerReadOnlyForm')]
    public function view()
    {
        $this->addDatabaseMapperResolver(ItemPreset::class, new QueryResult([$this->buildPreset()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingle()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Value deleted","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiple()
    {
        // The batch delete asserts that as many rows were affected as ids were sent.
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            return new QueryResult([], 2, 0);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'get',
                'index.php',
                ['r' => 'itemPreset/delete', 'items' => [100, 200]]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Values deleted","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/delete', 'items' => []])
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
    public function saveCreate()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'itemPreset/saveCreate'],
                self::sessionTimeoutFields(900)
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Value created","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $this->addDatabaseMapperResolver(ItemPreset::class, new QueryResult([$this->buildPreset()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'itemPreset/saveEdit/100'],
                self::sessionTimeoutFields(1200)
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Value updated","data":null}');
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
            ItemPreset::class,
            QueryResult::withTotalNumRows([$this->buildPreset(), $this->buildPreset()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'itemPreset/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The form reads the preset type and its payload from the request, not the route, and
     * requires the preset to be tied to a user, group or profile.
     *
     * @return array<string, string|int>
     */
    private static function sessionTimeoutFields(int $timeout): array
    {
        return [
            'type' => ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT,
            'ip_address' => '192.168.0.0/24',
            'timeout' => $timeout,
            'priority' => 10,
            'user_id' => self::$faker->randomNumber(3),
        ];
    }

    /**
     * A session-timeout preset, whose payload lives in the serialized data column.
     */
    private function buildPreset(): ItemPreset
    {
        return (new ItemPreset(
            [
                'id' => self::$faker->randomNumber(3),
                'type' => ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT,
                'userId' => self::$faker->randomNumber(3),
                'fixed' => 0,
                'priority' => 10,
            ]
        ))->dehydrate(new SessionTimeout(self::$faker->ipv4(), 900));
    }

    /**
     * An editable preset form posts back to the item-preset save route, and renders the fields
     * of the session-timeout branch the view was built for.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $forms = $crawler->filterXPath('//form[@name="frmItemPreset"]')->extract(['data-action-route']);

        self::assertNotEmpty($forms);
        self::assertStringStartsWith('itemPreset/save', $forms[0]);

        $fields = $crawler->filterXPath('//form[@name="frmItemPreset"]//input')->extract(['name']);

        self::assertContains('ip_address', $fields);
        self::assertContains('timeout', $fields);
    }

    /**
     * Viewing a preset renders the same fields with no route to submit to, so it cannot be
     * saved back.
     */
    private function outputCheckerReadOnlyForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $forms = $crawler->filterXPath('//form[@name="frmItemPreset"]')->extract(['data-action-route']);

        self::assertNotEmpty($forms);
        self::assertSame('', $forms[0]);

        $fields = $crawler->filterXPath('//form[@name="frmItemPreset"]//input')->extract(['name']);

        self::assertContains('timeout', $fields);
    }

    /**
     * One row per preset returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath(
            '//table/tbody[@id="data-rows-tblItemPreset"]//tr[string-length(@data-item-id) > 0]'
        );

        self::assertCount(2, $rows);
    }
}
