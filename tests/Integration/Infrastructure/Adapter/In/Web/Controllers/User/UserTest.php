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
use SP\Application\Notification\Ports\MailService;
use SP\Domain\Common\Dtos\QueryResult;
use SP\Domain\Core\Acl\AclInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\User\Models\User;
use SP\Domain\User\Models\UserGroup as UserGroupModel;
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
     * If a picker's data (the user group list, here) cannot be loaded, the create form
     * must degrade to a JSON error response rather than leaking an unhandled exception.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function createFailsWhenGroupsCannotBeLoaded()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getMapClassName() === UserGroupModel::class) {
                throw ConstraintException::error('Unable to load user groups');
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/create'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Unable to load user groups","data":null}');
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
     * Editing an id that no longer exists must surface an error instead of rendering
     * whatever the lookup happened to return: the controller has to check the result,
     * not assume the row is there because an id was passed on the route.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editUnknownUser()
    {
        // No mapper resolver registered for User::class: the lookup comes back empty,
        // exactly as it would for an id that was deleted after the row was listed.
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/edit/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"User does not exist","data":null}');
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
     * Same lookup guard as the main edit form: the password form must not render for
     * a user id that isn't there instead of quietly showing an unrelated blank form.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function editPassUnknownUser()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/editPass/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"User does not exist","data":null}');
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
     * Deleting an id with no matching row must be reported as an error, not treated as
     * a no-op success — otherwise a caller can't tell a real deletion from a typo'd id.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteSingleNotFound()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getOnErrorMessage() === 'Error while deleting the user') {
                return new QueryResult([], 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/delete/999'])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"User not found","data":null}');
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
     * The batch delete counts affected rows against the ids sent; when fewer rows were
     * removed than requested (one id had already gone, say) it must report the mismatch
     * instead of claiming every user was deleted.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function deleteMultiplePartialFailure()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getOnErrorMessage() === 'Error while deleting the users') {
                // Two ids were requested but only one row was actually removed.
                return new QueryResult([], 1, 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('get', 'index.php', ['r' => 'user/delete', 'items' => [100, 200]])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while deleting the users","data":null}');
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
     * Checking "force password change" on create must actually kick off the recovery
     * mail, not just be accepted and dropped: this is the only place "changepass_enabled"
     * is read after the form parses it.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveCreateTriggersPasswordChangeMail()
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send');

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'user/saveCreate'],
                self::userFields() + self::passwordFields() + ['changepass_enabled' => '1']
            ),
            [MailService::class => $mailService]
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
     * The edit form enforces the same required fields as create; without a login the
     * save must be refused rather than silently persisting a blank one.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditWithoutLogin()
    {
        $fields = self::userFields();
        unset($fields['login']);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveEdit/100'], $fields)
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"A login is needed","data":null}');
    }

    /**
     * If the update statement touches no rows (the user was removed between opening
     * the form and submitting it, say) the save must report the failure instead of
     * quietly returning success for a write that never happened.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditFailsWhenNoRowsAffected()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getOnErrorMessage() === 'Error while updating the user') {
                return new QueryResult([], 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveEdit/100'], self::userFields())
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while updating the user","data":null}');
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
     * The password-change endpoint enforces its own permission separately from the rest of the
     * user's data: a caller without USER_EDIT_PASS must be refused before the form is even
     * looked at, since this is the one action that can lock the target user out of their account.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditPassDeniedByAcl()
    {
        $acl = $this->createStub(AclInterface::class);
        $acl->method('checkUserAccess')->willReturn(false);
        $acl->method('getRouteFor')->willReturnCallback(static fn(int $actionId) => (string)$actionId);

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'user/saveEditPass/100'],
                self::passwordFields()
            ),
            [AclInterface::class => $acl]
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString(
            '{"status":"ERROR","description":"You don\'t have permission to do this operation","data":null}'
        );
    }

    /**
     * The confirmation box exists so a typo isn't saved as the new password; a mismatch must
     * be refused rather than silently taking either value.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditPassMismatchedConfirmationIsRefused()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'user/saveEditPass/100'],
                ['password' => self::$faker->password(12), 'password_repeat' => self::$faker->password(12)]
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Passwords do not match","data":null}');
    }

    /**
     * An empty password is the only length/strength rule the form itself enforces (there is no
     * separate minimum-length or complexity check server-side); it must still be refused rather
     * than hashing and storing an empty string.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditPassBlankIsRefused()
    {
        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest('post', 'index.php', ['r' => 'user/saveEditPass/100'], [])
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Password cannot be blank","data":null}');
    }

    /**
     * The form never looks the target user up, so a nonexistent id only ever fails at the
     * repository's UPDATE, which touches zero rows. UserService::updatePass() checks that count
     * and throws instead of reporting success — this pins that a failed write is surfaced as an
     * error rather than a false "Password updated", i.e. the user's real password is left alone.
     *
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    #[Test]
    public function saveEditPassFailsWhenNoRowsAffected()
    {
        $this->databaseQueryResolver = function (QueryData $queryData): QueryResult {
            if ($queryData->getOnErrorMessage() === 'Error while updating the password') {
                return new QueryResult([], 0);
            }

            return new QueryResult([], 1, 100);
        };

        $container = $this->buildContainer(
            IntegrationTestCase::buildRequest(
                'post',
                'index.php',
                ['r' => 'user/saveEditPass/999'],
                self::passwordFields()
            )
        );

        IntegrationTestCase::runApp($container);

        $this->expectOutputString('{"status":"ERROR","description":"Error while updating the password","data":null}');
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
            // Not randomNumber(): it can roll a zero, and the form reads a zero group or profile
            // as "none given" and refuses the save. Once in a hundred runs, on CI, in whichever
            // pull request happened to be open at the time.
            'usergroup_id' => self::$faker->numberBetween(1, 99),
            'userprofile_id' => self::$faker->numberBetween(1, 99),
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
