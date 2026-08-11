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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Items;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Category\Models\Category;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Common\Models\Item;
use SP\Domain\Common\Models\Simple;
use SP\Domain\Notification\Models\Notification;
use SP\Domain\Tag\Models\Tag;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\IntegrationTestCase;

/**
 * Covers the item endpoints that feed the UI's select boxes. None of them had tests.
 *
 * They do not use the usual response envelope: the list is streamed as a raw JSON array, so the
 * assertions read that array directly.
 */
#[Group('integration')]
class ItemsTest extends IntegrationTestCase
{
    private const FIRST_NAME  = 'Alpha item';  // matched literally in the streamed JSON
    private const SECOND_NAME = 'Beta item';

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function categories()
    {
        $this->addDatabaseMapperResolver(
            Category::class,
            new QueryResult([
                new Category(['id' => 1, 'name' => self::FIRST_NAME]),
                new Category(['id' => 2, 'name' => self::SECOND_NAME]),
            ])
        );

        $this->whenRequesting('items/categories');

        $this->expectOutputRegex('/' . self::FIRST_NAME . '.*' . self::SECOND_NAME . '/s');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function clients()
    {
        // The clients a user may see come back through an account filter, which maps rows to
        // the generic Item rather than the Client model.
        $this->addDatabaseMapperResolver(
            Item::class,
            new QueryResult([
                new Item(['id' => 1, 'name' => self::FIRST_NAME]),
                new Item(['id' => 2, 'name' => self::SECOND_NAME]),
            ])
        );

        $this->whenRequesting('items/clients');

        $this->expectOutputRegex('/' . self::FIRST_NAME . '.*' . self::SECOND_NAME . '/s');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function tags()
    {
        $this->addDatabaseMapperResolver(
            Tag::class,
            new QueryResult([
                new Tag(['id' => 1, 'name' => self::FIRST_NAME]),
                new Tag(['id' => 2, 'name' => self::SECOND_NAME]),
            ])
        );

        $this->whenRequesting('items/tags');

        $this->expectOutputRegex('/' . self::FIRST_NAME . '.*' . self::SECOND_NAME . '/s');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function notifications()
    {
        $this->addDatabaseMapperResolver(
            Notification::class,
            new QueryResult([
                new Notification(['id' => 1, 'component' => 'sysPass', 'description' => self::FIRST_NAME]),
            ])
        );

        $this->whenRequesting('items/notifications');

        $this->expectOutputRegex('/' . self::FIRST_NAME . '/');
    }

    /**
     * The account picker labels each entry with its client, so the two names are joined rather
     * than listed separately.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerAccountsUser')]
    public function accountsUser()
    {
        $this->addDatabaseMapperResolver(
            Simple::class,
            new QueryResult([
                new Simple(['id' => 7, 'name' => self::FIRST_NAME, 'clientName' => 'Acme']),
            ])
        );

        $this->whenRequesting('items/accountsUser');
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
     * Each account is offered as "client - account", so both halves have to reach the label.
     */
    private function outputCheckerAccountsUser(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);
        self::assertCount(1, $json->data);
        self::assertSame('Acme - ' . self::FIRST_NAME, $json->data[0]->name);
    }
}
