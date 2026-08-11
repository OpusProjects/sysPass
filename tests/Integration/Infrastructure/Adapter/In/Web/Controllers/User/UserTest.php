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

namespace SP\Tests\Integration\Infrastructure\Adapter\In\Web\Controllers\User;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\User\Models\User;
use SP\Domain\User\Models\UserList;
use SP\Infrastructure\Database\QueryData;
use SP\Tests\Support\BodyChecker;
use SP\Tests\Support\Generators\UserDataGenerator;
use SP\Tests\Support\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class UserTest
 */
#[Group('integration')]
class UserTest extends IntegrationTestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function create()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/create'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function edit()
    {
        $this->addDatabaseMapperResolver(User::class, new QueryResult([$this->buildUser()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/edit/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerForm')]
    public function view()
    {
        $this->addDatabaseMapperResolver(User::class, new QueryResult([$this->buildUser()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/view/100'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * The password form is a separate endpoint; it must not carry the rest of the account
     * fields, only the two password boxes.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    #[BodyChecker('outputCheckerPasswordForm')]
    public function editPass()
    {
        $this->addDatabaseMapperResolver(User::class, new QueryResult([$this->buildUser()]));

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/editPass/100'])
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/delete/100'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"User deleted","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Users deleted","data":null}');
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
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/delete', 'items' => []])
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
                ['r' => 'user/saveCreate'],
                self::userFields() + self::passwordFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"User added","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEdit()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveEdit/100'], self::userFields())
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"User updated","data":null}');
    }

    /**
     * Each required field is enforced, so an incomplete account cannot be created.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutLogin()
    {
        $fields = self::userFields();
        unset($fields['login']);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveCreate'], $fields)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A login is needed","data":null}');
    }

    /**
     * A user has to belong to a profile: without one there would be nothing to derive their
     * permissions from.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateWithoutProfile()
    {
        $fields = self::userFields();
        unset($fields['userprofile_id']);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveCreate'], $fields)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A profile is needed","data":null}');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditPass()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'user/saveEditPass/100'],
                self::passwordFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"OK","description":"Password updated","data":null}');
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
            UserList::class,
            QueryResult::withTotalNumRows([$this->buildUserListItem(), $this->buildUserListItem()], 2)
        );

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/search', 'search' => 'test'])
        );

        IntegrationTestCase::runApp($container);
    }

    /**
     * @return array<string, string>
     */
    private static function passwordFields(): array
    {
        $password = self::$faker->password(12);

        return ['password' => $password, 'password_repeat' => $password];
    }

    /**
     * @return array<string, string|int>
     */
    private static function userFields(): array
    {
        return [
            'name' => self::$faker->name(),
            'login' => self::$faker->userName(),
            'email' => self::$faker->email(),
            'usergroup_id' => self::$faker->randomNumber(2),
            'userprofile_id' => self::$faker->randomNumber(2),
            'notes' => self::$faker->sentence(),
        ];
    }

    private function buildUser(): User
    {
        return UserDataGenerator::factory()->buildUserData();
    }

    private function buildUserListItem(): UserList
    {
        return new UserList(
            [
                'id' => self::$faker->randomNumber(3),
                'name' => self::$faker->name(),
                'login' => self::$faker->userName(),
                'email' => self::$faker->email(),
                'userGroupName' => self::$faker->colorName(),
                'userProfileName' => self::$faker->colorName(),
            ]
        );
    }

    /**
     * The account form carries the identity fields and the group/profile pickers.
     */
    private function outputCheckerForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $inputs = $crawler->filterXPath('//form[@name="frmUsers"]//input')->extract(['name']);
        $selects = $crawler->filterXPath('//form[@name="frmUsers"]//select')->extract(['name']);

        self::assertContains('name', $inputs);
        self::assertContains('login', $inputs);
        self::assertContains('usergroup_id', $selects);
        self::assertContains('userprofile_id', $selects);
    }

    /**
     * Changing a password is its own form: it offers the two password boxes and none of the
     * account fields, so it cannot be used to edit the rest of the user.
     */
    private function outputCheckerPasswordForm(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $inputs = $crawler->filterXPath('//form//input')->extract(['name']);

        self::assertContains('password', $inputs);
        self::assertContains('password_repeat', $inputs);

        // The account's name and login are shown for context but rendered disabled, so a browser
        // does not submit them: this form cannot be used to edit the rest of the user.
        self::assertCount(1, $crawler->filterXPath('//form//input[@name="login"][@disabled]'));
    }

    /**
     * One row per user returned by the search.
     */
    private function outputCheckerSearch(string $output): void
    {
        $json = json_decode($output);

        self::assertEquals('OK', $json->status);

        $crawler = new Crawler($json->data->html);
        $rows = $crawler->filterXPath('//table/tbody[@id="data-rows-tblUsers"]//tr[string-length(@data-item-id) > 0]');

        self::assertCount(2, $rows);
    }
}
