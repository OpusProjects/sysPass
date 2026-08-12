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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Models\AccountView;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\AccountDataGenerator;
use SP\Tests\Support\InjectVault;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the form for changing an account's password, which had no test.
 *
 * It is a separate endpoint from editing the account, and is reached under its own permission —
 * changing a password is not the same right as changing the rest of the account.
 */
#[Group('integration')]
#[InjectVault]
class EditPassControllerTest extends IntegrationTestCase
{
    /**
     * The form is built for the account named on the route and offers the two password boxes,
     * without the rest of the account's fields.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPasswordForm')]
    public function editPass()
    {
        $this->addDatabaseMapperResolver(
            AccountView::class,
            new QueryResult([AccountDataGenerator::factory()->buildAccountDataView()])
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'account/editPass/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The password boxes are what the form exists for; the account's other fields belong to the
     * edit endpoint, which is governed by a different permission.
     */
    private function outputCheckerPasswordForm(string $output): void
    {
        $crawler = new Crawler($output);
        $fields = $crawler->filterXPath('//form//input')->extract(['name']);

        self::assertContains('password', $fields);
        self::assertContains('password_repeat', $fields);

        // The account's own fields are shown as context but are not the point of this form —
        // editing them belongs to the edit endpoint, under a different permission.
        self::assertNotContains('notes', $fields);
    }
}
